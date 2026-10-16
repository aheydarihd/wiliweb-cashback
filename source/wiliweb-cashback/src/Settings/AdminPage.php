<?php

namespace WiliWeb\Cashback\Settings;

use WiliWeb\Cashback\Appearance\Assets;

final class AdminPage
{
    public function register(): void
    {
        add_action('admin_menu', function () {
            add_submenu_page('woocommerce', 'کش‌بک ویلی‌وب', 'کش‌بک و کیف پول', 'manage_options', 'wwcb-settings', [$this, 'page']);
        });
        add_action('admin_init', [$this, 'settings']);
        add_action('admin_enqueue_scripts', function ($hook) {
            if ($hook !== 'woocommerce_page_wwcb-settings') {
                return;
            }
            Assets::enqueue();
            wp_enqueue_style('wwcb-admin', plugins_url('assets/css/admin.css', WWCB_FILE), ['wwcb'], WWCB_VERSION);
            wp_enqueue_script('wwcb-admin', plugins_url('assets/js/admin.js', WWCB_FILE), [], WWCB_VERSION, true);
        });
    }

    public function settings(): void
    {
        register_setting('wwcb', 'wwcb_settings', ['sanitize_callback' => [Settings::class, 'sanitize'], 'show_in_rest' => false]);
    }

    private function field(string $key, string $label, string $help, string $type = 'text', string $unit = ''): void
    {
        $s = Settings::get();
        $v = $key === 'rate' ? $s['rate_bps'] / 100 : ($s[$key] ?? '');
        $id = 'wwcb-'.$key;
        $name = 'wwcb_settings['.$key.']';
        echo '<div class="wwcb-field"><label for="'.esc_attr($id).'">'.esc_html($label).'</label>';
        if ($type === 'checkbox') {
            echo '<div class="wwcb-check"><input type="hidden" name="'.esc_attr($name).'" value="0"><input id="'.esc_attr($id).'" name="'.esc_attr($name).'" type="checkbox" value="1" aria-describedby="'.esc_attr($id).'-help" '.checked($v, 1, false).'><span>فعال</span></div>';
        } elseif ($type === 'textarea') {
            echo '<textarea id="'.esc_attr($id).'" rows="4" name="'.esc_attr($name).'" aria-describedby="'.esc_attr($id).'-help">'.esc_textarea($v).'</textarea>';
        } elseif ($type === 'select') {
            echo '<select id="'.esc_attr($id).'" name="'.esc_attr($name).'" aria-describedby="'.esc_attr($id).'-help"><option value="pattern" '.selected($v, 'pattern', false).'>پترن تأییدشده در IPPanel</option><option value="text" '.selected($v, 'text', false).'>پیامک متنی با خط پنل</option></select>';
        } else {
            $limits = ['rate' => 'min="0" max="100" step="0.01"', 'validity_days' => 'min="1" max="3650"', 'min_spend_toman' => 'min="0" max="100000000000"', 'round_step_toman' => 'min="1" max="100000000"'];
            echo '<div class="wwcb-input-unit"><input id="'.esc_attr($id).'" type="'.esc_attr($type).'" name="'.esc_attr($name).'" value="'.esc_attr($type === 'password' ? '' : $v).'" aria-describedby="'.esc_attr($id).'-help" '.($limits[$key] ?? '').' '.($type === 'password' ? 'autocomplete="new-password" data-key-saved="'.($v !== '' ? '1' : '0').'"' : '').'>'.($unit !== '' ? '<span>'.esc_html($unit).'</span>' : '').'</div>';
            if ($type === 'password') {
                echo '<p class="wwcb-key-state">'.($v !== '' ? 'کلید قبلاً ذخیره شده؛ برای حفظ آن این کادر را خالی بگذارید.' : 'هنوز کلیدی ذخیره نشده است.').'</p><label class="wwcb-clear-key"><input type="checkbox" name="wwcb_settings[clear_key]" value="1"> حذف کلید ذخیره‌شده</label>';
            }
        }
        echo '<p id="'.esc_attr($id).'-help" class="wwcb-help">'.esc_html($help).'</p></div>';
    }

    private function tokens(string $target): void
    {
        echo '<div class="wwcb-tokens" data-token-target="'.esc_attr($target).'"><span>درج در متن:</span>';
        foreach (['name' => 'نام مشتری', 'amount' => 'مبلغ اعتبار', 'balance' => 'کل موجودی', 'expires' => 'تاریخ انقضا', 'order' => 'شماره سفارش'] as $token => $label) {
            if ($target === 'text_hint' && $token !== 'amount') {
                continue;
            }
            echo '<button type="button" data-token="'.esc_attr($token).'">'.esc_html($label).'</button>';
        }
        echo '</div>';
    }

    public function page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s = Settings::get();
        ?>
        <div class="wrap wwcb wwcb-settings" dir="rtl">
            <header class="wwcb-head"><div><span class="wwcb-eyebrow">ویلی‌وب · تنظیمات این فروشگاه</span><h1>کش‌بک و کیف پول</h1><p>اعتبار خرید بعدی مشتری را تنظیم کنید؛ از مبلغ و مهلت استفاده تا پیامک‌های اطلاع‌رسانی.</p></div><span class="wwcb-badge">تمام مبلغ‌ها به تومان</span></header>
            <?php settings_errors('wwcb_settings'); ?>
            <?php if (!\WiliWeb\Cashback\Checkout\Classic::supported()) : ?>
                <div class="notice notice-warning inline"><p>واحد ووکامرس باید تومان (IRT) و مالیات غیرفعال باشد. در وضعیت فعلی، کیف پول در پرداخت غیرفعال است.</p></div>
            <?php endif; ?>
            <form method="post" action="options.php" id="wwcb-settings-form">
                <?php settings_fields('wwcb'); ?>
                <nav class="wwcb-tabs" aria-label="بخش‌های تنظیمات">
                    <?php foreach (['rules' => 'قوانین اعتبار', 'sms' => 'پیامک و پترن‌ها', 'reminders' => 'یادآوری‌ها', 'appearance' => 'ظاهر و نمایش'] as $key => $label) : ?>
                        <a href="#wwcb-panel-<?php echo esc_attr($key); ?>" id="wwcb-tab-<?php echo esc_attr($key); ?>" data-tab="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></a>
                    <?php endforeach; ?>
                </nav>
                <div class="wwcb-layout"><main class="wwcb-main">
                    <section id="wwcb-panel-rules" class="wwcb-panel" aria-labelledby="wwcb-tab-rules">
                        <div class="wwcb-section-title"><h2>مشتری چقدر اعتبار می‌گیرد؟</h2><p>این قواعد برای اعتبارهای جدید اعمال می‌شوند. مبلغ و تاریخ اعتبارهای قبلی تغییر نمی‌کند.</p></div>
                        <div class="wwcb-card"><div class="wwcb-grid">
                            <?php
                            $this->field('rate', 'درصد کش‌بک', 'از مبلغ پرداختی کالاها، بعد از تخفیف و کسر کیف پول محاسبه می‌شود.', 'number', 'درصد');
        $this->field('validity_days', 'مهلت استفاده از هر اعتبار', 'هر خرید مهلت مستقل دارد؛ تاریخ انقضا از زمان ثبت اعتبار محاسبه می‌شود.', 'number', 'روز');
        $this->field('min_spend_toman', 'حداقل خرید برای مصرف کیف پول', 'مجموع کالاها پس از تخفیف و قبل از کسر کیف پول باید به این مبلغ برسد.', 'number', 'تومان');
        $this->field('round_step_toman', 'گردکردن اعتبار به بالا', 'مثلاً با گام ۵۰۰، اعتبار ۷۵٬۱۲۰ تومانی به ۷۵٬۵۰۰ تومان تبدیل می‌شود.', 'number', 'تومان');
        ?>
                        </div></div>
                        <div class="wwcb-example"><span class="wwcb-eyebrow">مثال با تنظیمات شما</span><h3>خرید ۲٬۰۰۰٬۰۰۰ تومانی</h3><p>اگر مشتری این مبلغ را بدون کیف پول بپردازد، <strong data-example-amount>۱۰۰٬۰۰۰ تومان</strong> اعتبار می‌گیرد و <strong data-example-days>۳۰ روز</strong> برای استفاده فرصت دارد.</p><small>خریدی که کامل با کیف پول پرداخت شود، کش‌بک جدید ندارد.</small></div>
                        <details class="wwcb-guide"><summary>اگر سفارش لغو یا مرجوع شود چه می‌شود؟</summary><p>کل اعتبار ایجادشده از آن خرید باطل می‌شود؛ حتی در مرجوعی بخشی از سفارش. اعتبار مصرف‌شده طبق قواعد برگشت سفارش و با تاریخ انقضای اصلی مدیریت می‌شود. جزئیات در یادداشت‌های سفارش ثبت می‌شود.</p></details>
                    </section>
                    <section id="wwcb-panel-sms" class="wwcb-panel" aria-labelledby="wwcb-tab-sms">
                        <div class="wwcb-section-title"><h2>راه‌اندازی پیامک کیف پول</h2><p>این پیامک‌ها مستقل از پیامک خرید موفق قالب هستند. برای ارسال، مشخصات پنل IPPanel را وارد کنید.</p></div>
                        <ol class="wwcb-steps"><li><b>۱. اتصال پنل</b><span>کلید API و شماره فرستنده</span></li><li><b>۲. روش ارسال</b><span>پترن تأییدشده یا متن آزاد</span></li><li><b>۳. ذخیره تنظیمات</b><span>و بررسی نتیجه در گزارش‌ها</span></li></ol>
                        <div class="wwcb-card"><div class="wwcb-card-title"><h3>اتصال به IPPanel</h3><span class="wwcb-status" data-sms-status>وضعیت تنظیمات</span></div>
                            <?php $this->field('sms_enabled', 'ارسال پیامک کیف پول', 'با فعال‌کردن این گزینه، دریافت اعتبار و نوبت‌های یادآوری به مشتری اطلاع داده می‌شود.', 'checkbox'); ?>
                            <div class="wwcb-grid"><?php
        $this->field('sms_key', 'کلید دسترسی (API Key)', 'از بخش کلید دسترسی / API پنل IPPanel کپی کنید. این کلید در صفحه نمایش داده نمی‌شود.', 'password');
        $this->field('sms_sender', 'شماره خط ارسال پیامک', 'شماره خط فعال پنل با قالب بین‌المللی؛ برای نمونه +98300012345. این کادر شماره موبایل مشتری نیست.');
        ?></div>
                            <?php $this->field('sms_mode', 'روش ارسال پیامک', 'در روش پترن، متن نهایی از پترن تأییدشده پنل خوانده می‌شود. ارسال متنی به دسترسی و محدودیت‌های خط شما بستگی دارد.', 'select'); ?>
                        </div>
                        <div data-sms-mode="pattern">
                            <div class="wwcb-callout"><b>پترن یعنی چه؟</b><p>پترن یک متن تأییدشده در IPPanel است که جای نام، مبلغ و تاریخ آن برای هر مشتری پر می‌شود. دو پترن بسازید: یکی برای دریافت اعتبار و یکی برای یادآوری. سپس کد هرکدام را اینجا وارد کنید.</p></div>
                            <details class="wwcb-guide"><summary>آموزش ساخت پترن؛ همراه متن‌های آماده</summary>
                                <ol><li>داخل IPPanel، دو الگو برای دریافت اعتبار و یادآوری بسازید. در متن پترن، متغیرها با علامت درصد نوشته می‌شوند؛ مثلاً <code>%amount%</code>.</li><li>برای نمونه‌های زیر، متغیرهای <code>name</code>، <code>amount</code>، <code>balance</code>، <code>expires</code> و <code>order</code> را در هر دو پترن تعریف کنید. نوع متغیرها را متن (<code>string</code>) بگذارید؛ مبلغ‌ها با جداکننده هزارگان ارسال می‌شوند.</li><li>پس از تأیید هر پترن، کد آن را در کارت مربوط وارد کنید. در جدول پایین فقط نام متغیر را بنویسید؛ مثلاً <code>amount</code>، بدون علامت درصد.</li></ol>
                                <p>این متن‌ها برای نام‌های پیش‌فرض جدول هستند؛ اگر نامی را عوض کردید، متن پترن را هم با همان نام هماهنگ کنید.</p>
                                <label for="wwcb-template-grant">نمونه آماده دریافت اعتبار</label><textarea id="wwcb-template-grant" class="wwcb-template" rows="3" readonly>%name% عزیز، از خرید شماره %order% مبلغ %amount% تومان اعتبار گرفتی. موجودی قابل استفاده: %balance% تومان. اعتبار این خرید تا %expires% معتبر است.</textarea>
                                <label for="wwcb-template-reminder">نمونه آماده یادآوری</label><textarea id="wwcb-template-reminder" class="wwcb-template" rows="3" readonly>%name% عزیز، %amount% تومان از اعتبار خرید شماره %order% تا %expires% قابل استفاده است. موجودی قابل استفاده: %balance% تومان.</textarea>
                                <p>متن‌ها قابل انتخاب و کپی هستند. <a href="https://ippanelcom.github.io/Edge-Document/fa/docs/pattern/create-pattern/" target="_blank" rel="noopener noreferrer">راهنمای رسمی پترن IPPanel (در پنجره جدید)</a></p>
                            </details>
                            <div class="wwcb-grid wwcb-message-grid">
                                <div class="wwcb-card"><span class="wwcb-eyebrow">بعد از خرید موفق</span><h3>اعلام دریافت اعتبار</h3><?php $this->field('pattern_grant', 'کد پترن دریافت اعتبار', 'کد پترن تأییدشده را از IPPanel کپی کنید؛ کد پترن با متن پیامک فرق دارد.'); ?><p class="wwcb-sample">سارا عزیز، از خرید شماره ۱۲۳۴ مبلغ ۱۰۰٬۰۰۰ تومان اعتبار گرفتی. موجودی قابل استفاده: ۲۵۰٬۰۰۰ تومان. اعتبار این خرید تا ۱۴۰۵/۰۸/۰۸ معتبر است.</p></div>
                                <div class="wwcb-card"><span class="wwcb-eyebrow">قبل از پایان مهلت</span><h3>یادآوری استفاده از اعتبار</h3><?php $this->field('pattern_reminder', 'کد پترن یادآوری', 'کد پترن یادآوری تأییدشده را وارد کنید. نوبت‌های ارسال در بخش «یادآوری‌ها» تنظیم می‌شوند.'); ?><p class="wwcb-sample">سارا عزیز، ۱۰۰٬۰۰۰ تومان از اعتبار کیف پولت تا ۱۴۰۵/۰۸/۰۸ قابل استفاده است. موجودی قابل استفاده: ۲۵۰٬۰۰۰ تومان.</p></div>
                            </div>
                            <div class="wwcb-card"><h3>جایگذاری اطلاعات در پترن</h3><p class="wwcb-help">نام متغیرهای پترن را دقیقاً مطابق IPPanel بنویسید؛ بدون علامت درصد یا آکولاد. مثلاً اگر در پنل نام مشتری <code>customer</code> است، مقابل «نام مشتری» بنویسید <code>customer</code>.</p><p class="wwcb-help">این نگاشت برای هر دو پترن مشترک است؛ هر دو باید متغیرهای همین جدول را بپذیرند. ردیف‌های اضافی را حذف کنید.</p>
                                <div data-mapping-builder hidden><div class="wwcb-mapping-heading"><span>اطلاعاتی که ارسال می‌شود</span><span>نام متغیر در IPPanel</span></div><div data-mapping-rows></div><button type="button" class="wwcb-secondary" data-mapping-add>افزودن متغیر</button><p class="wwcb-error" data-mapping-error role="alert"></p></div>
                                <div data-mapping-raw><?php $this->field('pattern_variables', 'نگاشت متغیرها', 'قالب: نام متغیر در پنل=نام داده. نام داده‌های مجاز: name, amount, balance, expires, order.', 'textarea'); ?></div>
                            </div>
                        </div>
                        <div data-sms-mode="text"><div class="wwcb-callout"><b>ویرایش متن ارسالی</b><p>روی نام اطلاعات زیر هر کادر بزنید تا در متن درج شود. پیش‌نمایش با اطلاعات نمونه ساخته می‌شود و پیامکی ارسال نمی‌کند.</p></div>
                            <?php foreach (['text_grant' => ['پیام دریافت اعتبار', 'مبلغ اعتبار همین خرید و کل موجودی مشتری را در متن قرار دهید.'], 'text_reminder' => ['پیام یادآوری', '«مبلغ اعتبار» باقی‌مانده اعتبار در آستانه انقضاست؛ «کل موجودی» همه اعتبارهای قابل استفاده است.']] as $key => [$label, $help]) : ?>
                                <div class="wwcb-card"><?php $this->field($key, $label, $help, 'textarea');
                                $this->tokens($key); ?><div class="wwcb-preview"><span>پیش‌نمایش آموزشی</span><p data-preview="<?php echo esc_attr($key); ?>"></p></div></div>
                            <?php endforeach; ?>
                        </div>
                        <details class="wwcb-guide"><summary>از کجا بفهمم پیامک ارسال شده؟</summary><p>پس از ذخیره، نتیجه ارسال‌های واقعی را در ووکامرس ← وضعیت ← گزارش‌ها، با منبع <code>wiliweb-cashback</code> بررسی کنید. نمایش وضعیت این صفحه فقط کامل‌بودن تنظیمات را نشان می‌دهد؛ اتصال واقعی به IPPanel را تأیید نمی‌کند.</p><p>نمونه‌های این صفحه پیشنهاد متن هستند. در روش پترن، تغییر متن نهایی باید داخل IPPanel انجام شود.</p></details>
                    </section>
                    <section id="wwcb-panel-reminders" class="wwcb-panel" aria-labelledby="wwcb-tab-reminders">
                        <div class="wwcb-section-title"><h2>چه زمانی به مشتری یادآوری کنیم؟</h2><p>هر نوبت نسبت به تاریخ انقضای همان اعتبار محاسبه می‌شود؛ خریدهای مختلف زمان‌بندی مستقل دارند.</p></div>
                        <div class="wwcb-callout"><b>عددها، روز باقی‌مانده تا انقضا هستند</b><p>مثلاً «۱۴» یعنی وقتی فقط ۱۴ روز از مهلت اعتبار مانده است، پیامک ارسال شود؛ نه ۱۴ روز بعد از خرید.</p></div>
                        <div class="wwcb-card"><div class="wwcb-card-title"><h3>نوبت‌های ارسال</h3><span class="wwcb-badge" data-reminder-count></span></div><p class="wwcb-help">روز تکراری وارد نکنید. هر عدد باید حداقل ۱ و کمتر از مهلت اعتبار باشد. حذف همه نوبت‌ها، یادآوری را متوقف می‌کند.</p>
                            <input type="hidden" name="wwcb_settings[reminder_days]" value=""><div data-wwcb-reminders>
                                <?php foreach ($s['reminder_days'] as $day) : ?>
                                    <div class="wwcb-reminder"><label>وقتی <input type="number" min="1" max="<?php echo esc_attr($s['validity_days'] - 1); ?>" name="wwcb_settings[reminder_days][]" value="<?php echo esc_attr($day); ?>" aria-label="روز باقی‌مانده تا انقضا"> روز تا انقضا مانده</label><button type="button" class="wwcb-remove" data-wwcb-remove>حذف نوبت</button><p class="wwcb-help" data-reminder-help></p></div>
                                <?php endforeach; ?>
                            </div><p class="wwcb-help" data-reminder-empty hidden>یادآوری‌ای تعریف نشده است. پیام دریافت اعتبار همچنان طبق تنظیمات پیامک ارسال می‌شود.</p><button type="button" class="wwcb-secondary" data-wwcb-add>+ افزودن نوبت یادآوری</button><p class="wwcb-error" data-reminder-error role="alert"></p>
                        </div>
                        <div class="wwcb-example"><h3>زمان‌بندی با مهلت فعلی شما</h3><div data-reminder-timeline></div><p class="wwcb-help">این مثال از زمان ثبت اعتبار محاسبه می‌شود. ساعت واقعی ارسال به اجرای زمان‌بندی سایت بستگی دارد.</p></div>
                        <div class="wwcb-callout" data-reminder-sms-note><b>برای ارسال یادآوری، پیامک باید فعال باشد</b><p>فعال‌بودن پیامک، شماره خط و روش ارسال را در بخش «پیامک و پترن‌ها» بررسی کنید.</p></div>
                    </section>
                    <section id="wwcb-panel-appearance" class="wwcb-panel" aria-labelledby="wwcb-tab-appearance">
                        <div class="wwcb-section-title"><h2>اعتبار کجا به مشتری نمایش داده شود؟</h2><p>نمایش‌ها از فونت سایت پیروی می‌کنند. متن‌ها و محل نمایش را برای همین فروشگاه تنظیم کنید.</p></div>
                        <div class="wwcb-card"><h3>محل‌های نمایش</h3><div class="wwcb-grid"><?php
                        $this->field('show_product', 'صفحه محصول', 'مبلغ اعتبار احتمالی از خرید محصول، کنار اطلاعات خرید نمایش داده می‌شود.', 'checkbox');
        $this->field('show_cart', 'سبد خرید', 'مشتری اعتبار احتمالی خرید و وضعیت کیف پول خود را می‌بیند.', 'checkbox');
        $this->field('show_checkout', 'صفحه پرداخت', 'موجودی قابل استفاده و دکمه اعمال یا حذف کیف پول نمایش داده می‌شود.', 'checkbox');
        $this->field('show_popup', 'پاپ‌آپ پس از ورود', 'در حساب کاربری، یک‌بار در هر ورود و فقط برای موجودی قابل استفاده نمایش داده می‌شود.', 'checkbox');
        ?></div></div>
                        <div class="wwcb-card"><?php $this->field('font_family', 'نام فونت (اختیاری)', 'خالی بگذارید تا فونت قالب / سایت استفاده شود. برای انتخاب دستی، فقط نام فونتی که قبلاً در سایت بارگذاری شده را بنویسید؛ مثلاً Vazirmatn.'); ?></div>
                        <div class="wwcb-card"><?php $this->field('text_hint', 'متن اعلام اعتبار خرید', 'جای مبلغ از {amount} استفاده کنید تا مبلغ محاسبه‌شده همان خرید نمایش داده شود.', 'textarea');
        $this->tokens('text_hint'); ?><div class="wwcb-preview"><span>پیش‌نمایش با مبلغ نمونه</span><p data-preview="text_hint"></p></div></div>
                    </section>
                </main><aside class="wwcb-sidebar" aria-label="راهنمای تنظیمات"><div class="wwcb-card"><span class="wwcb-eyebrow">راهنمای این بخش</span><h3 data-guide-title>قوانین اعتبار</h3><p data-guide-body>از درصد و مهلت اعتبار شروع کنید. مثال خرید به شما نشان می‌دهد مشتری با این تنظیمات چقدر اعتبار می‌گیرد.</p></div><div class="wwcb-card wwcb-summary"><h3>خلاصه تنظیمات فرم</h3><dl><div><dt>کش‌بک</dt><dd data-summary-rate></dd></div><div><dt>مهلت هر خرید</dt><dd data-summary-days></dd></div><div><dt>حداقل مصرف</dt><dd data-summary-min></dd></div><div><dt>یادآوری‌ها</dt><dd data-summary-reminders></dd></div></dl><small>تغییرات پس از زدن «ذخیره تنظیمات» اعمال می‌شوند.</small></div></aside></div>
                <div class="wwcb-savebar"><div><strong data-save-state role="status" aria-live="polite">تنظیمات این فروشگاه</strong><span>همه بخش‌ها با هم ذخیره می‌شوند.</span></div><button type="submit" class="wwcb-primary">ذخیره تنظیمات</button></div>
            </form>
        </div>
        <?php
    }
}
