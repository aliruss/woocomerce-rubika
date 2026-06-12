"""FastAPI Telegram relay for WooCommerce Rubika Bridge.

Receives product payloads from WordPress, validates authentication/HMAC,
downloads product images, sends them to Telegram Bot API, and cleans up temp
files. Secrets are read from environment variables only and are never logged.
"""

from __future__ import annotations

import hashlib
import hmac
import html
import json
import logging
import os
import re
import shutil
import tempfile
import time
import uuid
from pathlib import Path
from typing import Any, Optional
from urllib.parse import urlparse

import requests
from dotenv import load_dotenv
from fastapi import FastAPI, Header, HTTPException, Request
from pydantic import BaseModel, Field, HttpUrl, ValidationError, model_validator, validator
from starlette.responses import JSONResponse

load_dotenv()

TELEGRAM_BOT_TOKEN = os.getenv("TELEGRAM_BOT_TOKEN", "").strip()
TELEGRAM_CHAT_ID = os.getenv("TELEGRAM_CHAT_ID", "").strip()
RELAY_API_KEY = os.getenv("RELAY_API_KEY", "").strip()
RELAY_HMAC_SECRET = os.getenv("RELAY_HMAC_SECRET", "").strip()
ALLOWED_ORIGINS = [origin.strip() for origin in os.getenv("ALLOWED_ORIGINS", "").split(",") if origin.strip()]
MAX_IMAGE_SIZE_MB = float(os.getenv("MAX_IMAGE_SIZE_MB", "10"))
LOG_LEVEL = os.getenv("LOG_LEVEL", "INFO").upper()
TEMP_DIR = Path(os.getenv("TEMP_DIR", tempfile.gettempdir())).resolve()
REQUEST_TIMEOUT = 30
TELEGRAM_API_BASE = f"https://api.telegram.org/bot{TELEGRAM_BOT_TOKEN}"
MAX_TELEGRAM_MEDIA = 10
CAPTION_LIMIT = 1024
MESSAGE_LIMIT = 4096
ALLOWED_IMAGE_MIMES = {
    "image/jpeg": ".jpg",
    "image/jpg": ".jpg",
    "image/png": ".png",
    "image/webp": ".webp",
    "image/gif": ".gif",
}

logging.basicConfig(
    level=getattr(logging, LOG_LEVEL, logging.INFO),
    format="%(asctime)s %(levelname)s %(name)s %(message)s",
)
logger = logging.getLogger("telegram-relay")


class SafeLogAdapter(logging.LoggerAdapter):
    def process(self, msg: str, kwargs: dict[str, Any]) -> tuple[str, dict[str, Any]]:
        extra = self.extra.copy()
        extra.update(kwargs.pop("extra", {}))
        request_id = extra.pop("request_id", "-")
        product_id = extra.pop("product_id", "-")
        network = extra.pop("network", "telegram")
        safe_extra = " ".join(f"{key}={value}" for key, value in extra.items())
        prefix = f"request_id={request_id} product_id={product_id} network={network}"
        return f"{prefix} {msg}" + (f" {safe_extra}" if safe_extra else ""), kwargs


log = SafeLogAdapter(logger, {})
app = FastAPI(title="WooCommerce Telegram Relay", version="1.0.0")


class ImagePayload(BaseModel):
    id: int
    url: HttpUrl
    mime: Optional[str] = None


class ProductPayload(BaseModel):
    id: int
    title: str = ""
    url: HttpUrl
    price: str = ""
    short_description: str = ""
    social_text: str = ""
    caption: Optional[str] = None
    images: list[ImagePayload] = Field(default_factory=list)


class ManualMessagePayload(BaseModel):
    text: str = ""
    images: list[ImagePayload] = Field(default_factory=list)


class OptionsPayload(BaseModel):
    image_count: int = 1
    parse_mode: str = "HTML"
    send_as_album: bool = True

    @validator("parse_mode")
    def validate_parse_mode(cls, value: str) -> str:
        normalized = (value or "HTML").upper()
        if normalized in {"NONE", ""}:
            return "NONE"
        if normalized not in {"HTML", "MARKDOWN", "MARKDOWNV2"}:
            return "HTML"
        return normalized


class RelayPayload(BaseModel):
    network: str
    request_id: str
    action: Optional[str] = None
    type: str = "product"
    product: Optional[ProductPayload] = None
    message: Optional[ManualMessagePayload] = None
    options: OptionsPayload = Field(default_factory=OptionsPayload)

    @validator("network")
    def validate_network(cls, value: str) -> str:
        if value != "telegram":
            raise ValueError("unsupported network")
        return value

    @model_validator(mode="after")
    def validate_payload_type(self) -> "RelayPayload":
        if self.action == "ping":
            return self
        payload_type = self.type or "product"
        if payload_type == "manual":
            if not self.message or (not self.message.text.strip() and not self.message.images):
                raise ValueError("manual payload requires text or images")
        elif not self.product:
            raise ValueError("product payload requires product")
        return self


def validation_error_detail(exc: ValidationError) -> list[dict[str, Any]]:
    return exc.errors(include_context=False)


def require_config() -> None:
    missing = [name for name, value in {
        "TELEGRAM_BOT_TOKEN": TELEGRAM_BOT_TOKEN,
        "TELEGRAM_CHAT_ID": TELEGRAM_CHAT_ID,
        "RELAY_API_KEY": RELAY_API_KEY,
    }.items() if not value]
    if missing:
        raise RuntimeError("Missing required environment variables: " + ", ".join(missing))


def make_response(success: bool, payload: RelayPayload | None, method: str, **extra: Any) -> JSONResponse:
    body = {
        "success": success,
        "request_id": payload.request_id if payload else extra.get("request_id"),
        "product_id": payload.product.id if payload and payload.product else extra.get("product_id"),
        "network": "telegram",
        "method": method,
    }
    body.update(extra)
    return JSONResponse(body, status_code=200 if success else extra.get("status_code", 400))


def verify_origin(request: Request) -> None:
    if not ALLOWED_ORIGINS:
        return
    origin = request.headers.get("origin") or request.headers.get("referer") or ""
    if not origin:
        raise HTTPException(status_code=403, detail="Origin is required")
    parsed = urlparse(origin)
    normalized = f"{parsed.scheme}://{parsed.netloc}" if parsed.scheme and parsed.netloc else origin.rstrip("/")
    if normalized not in ALLOWED_ORIGINS:
        raise HTTPException(status_code=403, detail="Origin is not allowed")


def verify_auth(api_key: str | None, signature: str | None, body: bytes) -> None:
    if not api_key:
        raise HTTPException(status_code=401, detail="Missing API key")
    if not hmac.compare_digest(api_key, RELAY_API_KEY):
        raise HTTPException(status_code=403, detail="Invalid API key")
    if RELAY_HMAC_SECRET:
        if not signature:
            raise HTTPException(status_code=401, detail="Missing HMAC signature")
        expected = hmac.new(RELAY_HMAC_SECRET.encode("utf-8"), body, hashlib.sha256).hexdigest()
        supplied = signature.replace("sha256=", "", 1)
        if not hmac.compare_digest(supplied, expected):
            raise HTTPException(status_code=403, detail="Invalid HMAC signature")


def safe_html_text(text: str, parse_mode: str) -> tuple[str, Optional[str]]:
    if parse_mode == "NONE":
        return text, None
    if parse_mode == "HTML":
        escaped = html.escape(text or "", quote=False)
        return escaped.replace("\n", "\n"), "HTML"
    # Markdown is accepted but user content is safer without parse mode.
    return text or "", None


def payload_text(payload: RelayPayload) -> str:
    if payload.type == "manual" and payload.message:
        return payload.message.text or ""
    product = payload.product
    if not product:
        return ""
    if product.caption:
        return product.caption
    text = product.social_text or product.short_description or product.title
    parts = [text, product.price, str(product.url)]
    return "\n\n".join(part for part in parts if part)


def ensure_link_in_caption(caption: str, product_url: str, limit: int = CAPTION_LIMIT) -> str:
    if len(caption) <= limit and product_url in caption:
        return caption
    suffix = f"\n\n{product_url}"
    available = max(0, limit - len(suffix) - 1)
    shortened = caption[:available].rstrip()
    return (shortened + "…" + suffix)[:limit]


def split_message(text: str, limit: int = MESSAGE_LIMIT) -> list[str]:
    if len(text) <= limit:
        return [text]
    chunks: list[str] = []
    remaining = text
    while remaining:
        chunk = remaining[:limit]
        split_at = max(chunk.rfind("\n"), chunk.rfind(" "))
        if split_at > 500:
            chunk = remaining[:split_at]
        chunks.append(chunk)
        remaining = remaining[len(chunk):].lstrip()
    return chunks


def temp_path_for_image(image_id: int, content_type: str) -> Path:
    suffix = ALLOWED_IMAGE_MIMES.get(content_type.lower(), ".img")
    TEMP_DIR.mkdir(parents=True, exist_ok=True)
    return TEMP_DIR / f"relay-{int(time.time())}-{image_id}-{uuid.uuid4().hex}{suffix}"


def download_image(image: ImagePayload, request_id: str, product_id: int) -> Optional[Path]:
    context = {"request_id": request_id, "product_id": product_id, "network": "telegram"}
    try:
        with requests.get(str(image.url), stream=True, timeout=REQUEST_TIMEOUT) as response:
            log.info("Image download response received", extra={**context, "image_id": image.id, "status_code": response.status_code})
            if response.status_code != 200:
                return None
            content_type = response.headers.get("Content-Type", "").split(";")[0].strip().lower()
            if content_type not in ALLOWED_IMAGE_MIMES:
                log.warning("Image rejected due to unsupported content type", extra={**context, "image_id": image.id, "content_type": content_type})
                return None
            max_bytes = int(MAX_IMAGE_SIZE_MB * 1024 * 1024)
            path = temp_path_for_image(image.id, content_type)
            total = 0
            with path.open("wb") as handle:
                for chunk in response.iter_content(chunk_size=1024 * 64):
                    if not chunk:
                        continue
                    total += len(chunk)
                    if total > max_bytes:
                        handle.close()
                        path.unlink(missing_ok=True)
                        log.warning("Image rejected due to size limit", extra={**context, "image_id": image.id, "size": total})
                        return None
                    handle.write(chunk)
            if total <= 0 or not path.exists() or path.stat().st_size <= 0:
                path.unlink(missing_ok=True)
                log.warning("Image rejected because downloaded file is empty", extra={**context, "image_id": image.id})
                return None
            log.info("Image downloaded and validated", extra={**context, "image_id": image.id, "size": total, "content_type": content_type})
            return path
    except requests.RequestException as exc:
        log.warning("Image download failed", extra={**context, "image_id": image.id, "error": str(exc)})
        return None


def telegram_post(method: str, data: dict[str, Any], files: Optional[dict[str, Any]] = None) -> dict[str, Any]:
    url = f"{TELEGRAM_API_BASE}/{method}"
    response = requests.post(url, data=data, files=files, timeout=REQUEST_TIMEOUT)
    try:
        body = response.json()
    except ValueError:
        body = {"ok": False, "description": response.text[:200]}
    if response.status_code != 200 or not body.get("ok"):
        description = body.get("description") or f"Telegram HTTP {response.status_code}"
        raise RuntimeError(description)
    return body


def send_text_messages(text: str, parse_mode: Optional[str], context: dict[str, Any]) -> list[int]:
    message_ids: list[int] = []
    for chunk in split_message(text):
        data: dict[str, Any] = {"chat_id": TELEGRAM_CHAT_ID, "text": chunk}
        if parse_mode:
            data["parse_mode"] = parse_mode
        body = telegram_post("sendMessage", data)
        message_id = body.get("result", {}).get("message_id")
        if message_id:
            message_ids.append(message_id)
    log.info("Telegram text message sent", extra={**context, "count": len(message_ids)})
    return message_ids


def send_one_photo(path: Path, caption: str, parse_mode: Optional[str], context: dict[str, Any]) -> tuple[str, list[int]]:
    data: dict[str, Any] = {"chat_id": TELEGRAM_CHAT_ID, "caption": caption}
    if parse_mode:
        data["parse_mode"] = parse_mode
    with path.open("rb") as photo:
        body = telegram_post("sendPhoto", data, files={"photo": photo})
    message_id = body.get("result", {}).get("message_id")
    log.info("Telegram sendPhoto succeeded", extra=context)
    return "sendPhoto", [message_id] if message_id else []


def send_media_group(paths: list[Path], caption: str, parse_mode: Optional[str], context: dict[str, Any]) -> tuple[str, list[int]]:
    media = []
    files = {}
    handles = []
    try:
        for index, path in enumerate(paths[:MAX_TELEGRAM_MEDIA]):
            field = f"photo{index}"
            item: dict[str, Any] = {"type": "photo", "media": f"attach://{field}"}
            if index == 0 and caption:
                item["caption"] = caption
                if parse_mode:
                    item["parse_mode"] = parse_mode
            media.append(item)
            handle = path.open("rb")
            handles.append(handle)
            files[field] = handle
        data = {"chat_id": TELEGRAM_CHAT_ID, "media": json.dumps(media, ensure_ascii=False)}
        body = telegram_post("sendMediaGroup", data, files=files)
        message_ids = [item.get("message_id") for item in body.get("result", []) if item.get("message_id")]
        log.info("Telegram sendMediaGroup succeeded", extra={**context, "count": len(message_ids)})
        return "sendMediaGroup", message_ids
    finally:
        for handle in handles:
            handle.close()


def cleanup_files(paths: list[Path], context: dict[str, Any]) -> None:
    for path in paths:
        try:
            if path.exists():
                path.unlink()
                log.info("Temporary image cleaned up", extra={**context, "file": path.name})
        except OSError as exc:
            log.warning("Temporary image cleanup failed", extra={**context, "file": path.name, "error": str(exc)})


@app.get("/health")
def health() -> dict[str, Any]:
    return {"success": True, "service": "telegram-relay"}


@app.post("/send/telegram")
async def send_telegram(
    request: Request,
    x_relay_api_key: Optional[str] = Header(default=None),
    x_relay_signature: Optional[str] = Header(default=None),
) -> JSONResponse:
    try:
        require_config()
    except RuntimeError as exc:
        raise HTTPException(status_code=500, detail=str(exc)) from exc

    raw_body = await request.body()
    verify_origin(request)
    verify_auth(x_relay_api_key, x_relay_signature, raw_body)

    try:
        payload = RelayPayload.model_validate_json(raw_body)
    except ValidationError as exc:
        raise HTTPException(status_code=400, detail=validation_error_detail(exc)) from exc

    if payload.action == "ping":
        log.info(
            "Relay ping accepted",
            extra={"request_id": payload.request_id, "product_id": 0, "network": "telegram"},
        )
        return make_response(True, payload, "ping", message="pong")

    product_id = payload.product.id if payload.product else 0
    context = {"request_id": payload.request_id, "product_id": product_id, "network": "telegram"}
    log.info("Relay request accepted", extra=context)

    downloaded: list[Path] = []
    method = "unknown"
    message_ids: list[int] = []
    try:
        source_images = payload.message.images if payload.type == "manual" and payload.message else (payload.product.images if payload.product else [])
        selected_images = source_images[: max(0, payload.options.image_count)]
        for image in selected_images[:MAX_TELEGRAM_MEDIA]:
            path = download_image(image, payload.request_id, product_id)
            if path:
                downloaded.append(path)

        raw_text = payload_text(payload)
        escaped_text, parse_mode = safe_html_text(raw_text, payload.options.parse_mode)
        caption = ensure_link_in_caption(escaped_text, str(payload.product.url), CAPTION_LIMIT) if payload.product else escaped_text[:CAPTION_LIMIT]

        image_status = "sent_with_images"
        if len(downloaded) == 1:
            method, message_ids = send_one_photo(downloaded[0], caption, parse_mode, context)
            if escaped_text != caption:
                message_ids.extend(send_text_messages(escaped_text, parse_mode, context))
        elif len(downloaded) > 1:
            method, message_ids = send_media_group(downloaded, caption, parse_mode, context)
            if escaped_text != caption:
                message_ids.extend(send_text_messages(escaped_text, parse_mode, context))
        else:
            method = "sendMessage"
            image_status = "no_valid_image_available"
            message_ids = send_text_messages(escaped_text if escaped_text else "(no valid image was available)", parse_mode, context)
            log.warning("No valid image was available; sent text-only fallback", extra=context)

        return make_response(
            True,
            payload,
            method,
            telegram_message_id=message_ids[0] if message_ids else None,
            image_status=image_status,
            response_summary={"message_ids": message_ids, "valid_images": len(downloaded)},
        )
    except RuntimeError as exc:
        log.error("Telegram API failure", extra={**context, "method": method, "error": str(exc)})
        return make_response(False, payload, method, status_code=502, error=str(exc))
    except Exception as exc:  # noqa: BLE001 - return safe structured response for unexpected relay errors.
        log.exception("Internal relay error", extra=context)
        return make_response(False, payload, method, status_code=500, error="Internal relay error")
    finally:
        cleanup_files(downloaded, context)


if __name__ == "__main__":
    import uvicorn

    uvicorn.run("app:app", host="0.0.0.0", port=int(os.getenv("PORT", "8000")), reload=False)
