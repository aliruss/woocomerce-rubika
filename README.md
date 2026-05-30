# WooCommerce Social Bridge

افزونه سبک برای انتشار محصولات **منتشرشده** ووکامرس در شبکه‌های اجتماعی. در حال حاضر Rubika به‌صورت مستقیم و Telegram از طریق relay امن خارجی پشتیبانی می‌شوند.

> نام نمایشی افزونه از نسخه 1.3.0 به **WooCommerce Social Bridge** تغییر کرده است. فایل اصلی فعلاً با نام `woocommerce-rubika-bridge.php` باقی مانده تا سایت‌های فعلی بدون حذف تنظیمات، صف‌ها، متاهای محصول یا نیاز به فعال‌سازی مجدد به‌روزرسانی شوند.

## قابلیت‌ها
- ساختار چندشبکه‌ای سبک برای Rubika، Telegram و شبکه‌های آینده.
- تب‌های جداگانه تنظیمات: General، Rubika، Telegram، Queues و Logs / Diagnostics.
- تنظیمات مستقل Rubika: توکن بات، کانال، URL سایت، قالب پیام، تعداد تصویر، تصاویر مستثنی و تست پیام.
- تنظیمات مستقل Telegram: Relay URL، Relay API Key، HMAC اختیاری، تعداد تصویر، قالب پیام، parse mode، ارسال آلبومی و تست اتصال relay.
- قالب پیام قابل شخصی‌سازی با `{title}`، `{short_description}`، `{social_text}`، `{price}` و `{url}`.
- متن سفارشی در سطح محصول برای متن عمومی شبکه‌های اجتماعی، Rubika و Telegram.
- تشخیص قیمت محصولات ساده/متغیر و نمایش تومان.
- انتخاب تعداد تصویر ارسالی و انتخاب تصاویر مستثنی از **رسانه وردپرس** (Multi Select).
- پشتیبانی از تبدیل خودکار WEBP/AVIF و فرمت‌های ناسازگار به JPG نهایی و معتبر قبل از ارسال Rubika.
- صف انتشار network-aware با وضعیت جدا برای Rubika و Telegram.
- کنترل جداگانه صف‌ها: اجرای دستی هر شبکه، پاک‌سازی failed/skipped هر شبکه، پاک‌سازی کامل هر شبکه و requeue failed.
- بازه زمانی مجاز انتشار روزانه، فاصله ارسال، حداکثر تلاش مجدد و تأخیر تلاش مجدد.
- جلوگیری از ارسال تکراری با payload hash و امکان forced resend از اکشن دستی در صورت فعال بودن تنظیمات.
- گزینه «Do not publish out-of-stock products» برای جلوگیری از صف‌گذاری و ارسال محصول ناموجود در Rubika و Telegram.
- دکمه انتشار یک‌کلیکی از منوی «شبکه اجتماعی» در نوار ابزار مدیریت: ارسال به Rubika، Telegram یا همه شبکه‌های فعال.
- لاگ‌های امن با context شبکه، product_id، queue_id، request_id و پیام خطای پاک‌سازی‌شده، بدون نمایش secrets.

## نصب / ارتقا
1. فایل افزونه را در مسیر `wp-content/plugins/woocommerce-rubika-bridge/` نگه دارید یا جایگزین کنید.
2. افزونه را فعال/به‌روزرسانی کنید.
3. از مسیر WooCommerce → Social Bridge وارد تنظیمات شوید.
4. تب General را مرور کنید و سپس تب‌های Rubika و Telegram را جداگانه تکمیل کنید.

### نکته تغییر نام فایل
در نسخه 1.3.0 فایل اصلی برای سازگاری با نصب‌های قبلی تغییر نام داده نشده است. بنابراین نیازی به deactivate/reactivate دستی نیست. اگر در نسخه‌های آینده فایل به `woocommerce-social-bridge.php` منتقل شود، loader سازگار یا دستورالعمل ارتقای جداگانه ارائه خواهد شد.

## تب‌های تنظیمات
- **General:** روشن/خاموش کل افزونه، auto-publish محصولات جدید، کنترل محصول ناموجود، زمان‌بندی، retry، جلوگیری از ارسال تکراری، forced resend و نگهداری لاگ.
- **Rubika:** روشن/خاموش Rubika، توکن/کانال، قالب Rubika، تعداد تصویر، تصاویر مستثنی و تست پیام.
- **Telegram:** روشن/خاموش Telegram، URL و کلید relay، HMAC اختیاری، قالب Telegram، parse mode، تعداد تصویر، آلبوم و تست relay.
- **Queues:** نمایش جداگانه Rubika و Telegram با شمارش pending، processing، sent، failed و skipped و اکشن‌های مدیریت هر شبکه.
- **Logs / Diagnostics:** لاگ‌های اخیر، فیلتر شبکه، وضعیت محیط و پاک‌سازی لاگ/داده افزونه.

## کنترل محصول ناموجود
اگر گزینه **Do not publish out-of-stock products** روشن باشد:
- محصول ناموجود به‌صورت خودکار در صف قرار نمی‌گیرد.
- اگر محصول بعد از صف‌گذاری ناموجود شود، قبل از ارسال منتشر نمی‌شود و آیتم صف با وضعیت `skipped` یا `failed` (طبق تنظیمات) ثبت می‌شود.
- ارسال دستی نیز برای حالت امن همین قانون را رعایت می‌کند و دلیل در لاگ ثبت می‌شود.

## نکات عملکرد
- برای جلوگیری از فشار روی سرور و کانال، بازه 10 تا 20 دقیقه پیشنهاد می‌شود.
- برای Telegram از relay خارجی استفاده کنید و هرگز توکن Telegram Bot را در وردپرس ذخیره نکنید.
- لاگ‌ها را از تب Logs / Diagnostics بررسی کنید تا خطاهای API یا relay سریع‌تر رفع شوند.

## Changelog

### 1.3.0 - WooCommerce Social Bridge admin/queue update
- نام نمایشی افزونه به **WooCommerce Social Bridge** تغییر کرد، اما optionها و slugهای `wcrb_*` برای سازگاری حفظ شدند.
- تنظیمات به تب‌های General، Rubika، Telegram، Queues و Logs / Diagnostics تقسیم شد.
- کنترل‌های مدیریتی جدید اضافه شد: auto-publish، عدم انتشار محصولات ناموجود، رفتار صف برای محصول ناموجود، max retry، retry delay، duplicate prevention، forced resend و log retention.
- مدیریت صف‌ها برای Rubika و Telegram جدا شد و وضعیت `skipped` برای آیتم‌هایی که به‌دلیل موجودی ارسال نمی‌شوند نمایش داده می‌شود.
- اکشن‌های queue برای هر شبکه اضافه شد: process now، clear failed/skipped، clear network queue و requeue failed.
- لاگ‌ها با context شبکه و فیلتر سبک شبکه بهبود داده شدند و retention از تنظیمات خوانده می‌شود.

### 1.2.0 - Phase 1 multi-network foundation
- نسخه افزونه در هدر وردپرس و ثابت داخلی `WCRB_Plugin::VERSION` به‌روزرسانی شد و مقدار نصب‌شده در option `wcrb_plugin_version` نگهداری می‌شود.
- ساختار صف برای چند شبکه آماده شد و ستون‌های `network`، `payload_hash`، `request_id` و `last_response` به جدول صف اضافه می‌شوند؛ داده‌های قدیمی به شبکه `rubika` مهاجرت می‌کنند.
- تنظیمات مستقل Telegram اضافه شد: فعال/غیرفعال، Relay URL، Relay API Key، HMAC Secret اختیاری، تعداد تصویر، قالب پیام، parse mode و تست اتصال relay.
- متن سفارشی سطح محصول اضافه شد: متن عمومی شبکه‌های اجتماعی، متن اختصاصی Rubika و متن اختصاصی Telegram.
- اکشن‌های ارسال محصول به Rubika، Telegram و همه شبکه‌های فعال از منوی «شبکه اجتماعی» آماده شد.
- جلوگیری از ارسال تکراری با payload hash اضافه شد؛ ارسال دستی مستقیم همچنان به‌عنوان forced resend قابل انجام است.
- کلاینت امن WordPress-to-relay برای Telegram اضافه شد. وردپرس مستقیماً Telegram Bot API را صدا نمی‌زند و توکن تلگرام در افزونه ذخیره/ارسال نمی‌شود.

### Phase 2 - Python Telegram relay server
- پوشه `telegram-relay/` اضافه شد و شامل FastAPI relay server برای دریافت payload وردپرس و ارسال امن به Telegram Bot API است.
- Relay از `.env` برای `TELEGRAM_BOT_TOKEN`، `TELEGRAM_CHAT_ID`، `RELAY_API_KEY` و HMAC اختیاری استفاده می‌کند.
- endpoint اصلی `POST /send/telegram` با payload تولیدشده در Phase 1 سازگار است.
- Relay تصاویر محصول را دانلود/اعتبارسنجی می‌کند، با `sendPhoto` یا `sendMediaGroup` ارسال می‌کند و فایل‌های موقت را بعد از موفقیت یا خطا پاک می‌کند.
- مستندات نصب، systemd، Docker و نمونه curl در `telegram-relay/README.md` قرار دارد.

## Migration notes
- هنگام فعال‌سازی یا اولین اجرای افزونه، `dbDelta` ساختار جدول صف را به‌روز می‌کند.
- رکوردهای صف قدیمی که مقدار `network` ندارند با `rubika` مقداردهی می‌شوند تا رفتار قبلی حفظ شود.
- option اصلی `wcrb_settings`، جدول `wcrb_queue` و metaهای `_wcrb_*` عمداً حفظ شده‌اند.
- نسخه نصب‌شده در option `wcrb_plugin_version` ذخیره می‌شود و migration فقط هنگام قدیمی‌تر بودن نسخه اجرا می‌شود.

## چک‌لیست تست دستی ادمین
1. در صفحه Plugins نام **WooCommerce Social Bridge** و نسخه 1.3.0 را ببینید.
2. WooCommerce → Social Bridge را باز کنید و هر پنج تب را بررسی کنید.
3. Rubika test message را اجرا کنید.
4. Telegram relay test را با relay روشن اجرا کنید.
5. یک محصول موجود را از منوی «شبکه اجتماعی» به Rubika، Telegram و All enabled networks ارسال کنید.
6. یک محصول ناموجود را با گزینه block out-of-stock روشن تست کنید و لاگ/وضعیت skipped یا failed را بررسی کنید.
7. از تب Queues، صف Rubika و Telegram را جداگانه process/clear/requeue کنید.
8. از تب Logs / Diagnostics فیلتر شبکه و پاک‌سازی لاگ را تست کنید.
