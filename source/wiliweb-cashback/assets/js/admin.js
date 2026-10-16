(() => {
  'use strict';
  const root = document.querySelector('.wwcb-settings');
  if (!root) return;
  const $ = selector => root.querySelector(selector);
  const $$ = selector => [...root.querySelectorAll(selector)];
  const form = $('#wwcb-settings-form');
  const field = key => $('#wwcb-' + key);
  const format = value => new Intl.NumberFormat('fa-IR').format(value);
  const value = key => Number(field(key).value);
  const guides = {
    rules: ['قوانین اعتبار', 'از درصد و مهلت اعتبار شروع کنید. مثال خرید نشان می‌دهد مشتری با این تنظیمات چقدر اعتبار می‌گیرد.'],
    sms: ['راه‌اندازی پیامک', 'اول کلید و شماره خط را وارد کنید؛ سپس روش ارسال را انتخاب کنید. در روش پترن، کد دو پترن و نام متغیرهای تأییدشده در IPPanel لازم است.'],
    reminders: ['روزهای باقی‌مانده', 'عدد هر نوبت، تعداد روز باقی‌مانده تا انقضاست. برای مهلت ۳۰ روزه، نوبت ۱۴ یعنی ارسال ۱۶ روز پس از ثبت اعتبار.'],
    appearance: ['نمایش در فروشگاه', 'محل‌های نمایش را انتخاب کنید. خالی‌بودن نام فونت باعث می‌شود ظاهر افزونه از فونت سایت پیروی کند.']
  };
  const tabs = $$('[data-tab]');
  $('.wwcb-tabs').setAttribute('role', 'tablist');
  tabs.forEach(tab => {
    tab.setAttribute('role', 'tab');
    tab.setAttribute('aria-controls', 'wwcb-panel-' + tab.dataset.tab);
  });
  $$('.wwcb-panel').forEach(panel => { panel.setAttribute('role', 'tabpanel'); panel.tabIndex = 0; });
  const activate = (key, remember = true) => {
    if (!guides[key]) key = 'rules';
    tabs.forEach(tab => { const selected = tab.dataset.tab === key; tab.setAttribute('aria-selected', String(selected)); tab.tabIndex = selected ? 0 : -1; });
    $$('.wwcb-panel').forEach(panel => { panel.hidden = panel.id !== 'wwcb-panel-' + key; });
    $('[data-guide-title]').textContent = guides[key][0];
    $('[data-guide-body]').textContent = guides[key][1];
    if (remember) { try { sessionStorage.setItem('wwcb-settings-tab', key); } catch (_) {} }
  };
  let initial = location.hash.replace('#wwcb-panel-', '');
  if (!guides[initial]) { try { initial = sessionStorage.getItem('wwcb-settings-tab'); } catch (_) {} }
  activate(initial, false);
  tabs.forEach((tab, index) => {
    tab.addEventListener('click', event => { event.preventDefault(); activate(tab.dataset.tab); });
    tab.addEventListener('keydown', event => {
      let next;
      if (event.key === 'ArrowLeft') next = (index + 1) % tabs.length;
      if (event.key === 'ArrowRight') next = (index - 1 + tabs.length) % tabs.length;
      if (event.key === 'Home') next = 0;
      if (event.key === 'End') next = tabs.length - 1;
      if (next !== undefined) { event.preventDefault(); activate(tabs[next].dataset.tab); tabs[next].focus(); }
    });
  });
  const samples = { name: 'سارا', amount: '۱۰۰٬۰۰۰', balance: '۲۵۰٬۰۰۰', expires: '۱۴۰۵/۰۸/۰۸', order: '۱۲۳۴' };
  const dataLabels = { name: 'نام مشتری', amount: 'مبلغ اعتبار همین خرید / نوبت یادآوری', balance: 'کل موجودی قابل استفاده', expires: 'تاریخ انقضای همان اعتبار', order: 'شماره سفارش' };
  const mappingRaw = field('pattern_variables');
  const builder = $('[data-mapping-builder]');
  let mappingEnhanced = false;
  let rowId = 0;
  const mappingRow = (provider, data) => {
    const row = document.createElement('div');
    row.className = 'wwcb-mapping-row';
    const id = ++rowId;
    row.innerHTML = '<div><label class="wwcb-map-label">اطلاعات ارسالی</label><select aria-label="اطلاعات ارسالی متغیر"></select><small></small></div><div><label class="wwcb-map-label">نام متغیر در IPPanel</label><input type="text" autocomplete="off" spellcheck="false"><small>نام در پنل، بدون % یا { }</small></div><button type="button" class="wwcb-remove" data-mapping-remove>حذف</button>';
    const select = row.querySelector('select');
    Object.entries(dataLabels).forEach(([key, label]) => { const option = document.createElement('option'); option.value = key; option.textContent = label; select.append(option); });
    select.value = data;
    select.id = 'wwcb-data-' + id;
    row.querySelector('label').htmlFor = select.id;
    const input = row.querySelector('input');
    input.value = provider; input.id = 'wwcb-map-' + id; input.setAttribute('aria-label', 'نام متغیر در IPPanel، ردیف ' + id);
    row.querySelectorAll('label')[1].htmlFor = input.id;
    $('[data-mapping-rows]').append(row);
    return row;
  };
  const parts = mappingRaw.value.split(',').map(part => part.trim()).filter(Boolean);
  const parsed = parts.map(part => part.split('=').map(piece => piece.trim()));
  // Preserve unrecognized legacy mappings in the original editor instead of rewriting them.
  if (parsed.every(pair => pair.length === 2 && /^[A-Za-z0-9_-]+$/.test(pair[0]) && Object.prototype.hasOwnProperty.call(dataLabels, pair[1]))) {
    parsed.forEach(([provider, data]) => mappingRow(provider, data));
    builder.hidden = false;
    $('[data-mapping-raw]').hidden = true;
    mappingEnhanced = true;
  }
  const syncMapping = () => {
    if (!mappingEnhanced) return true;
    const seen = new Set();
    let error = '';
    const entries = $$('[data-mapping-rows] .wwcb-mapping-row').map(row => {
      const input = row.querySelector('input'); const select = row.querySelector('select');
      const provider = input.value.trim();
      row.querySelector('small').textContent = 'نمونه: ' + (samples[select.value] || '');
      let issue = '';
      if (!/^[A-Za-z0-9_-]+$/.test(provider)) issue = 'نام متغیر فقط با حروف انگلیسی، عدد، خط تیره یا زیرخط نوشته می‌شود.';
      else if (seen.has(provider)) issue = 'نام متغیرهای IPPanel نباید تکراری باشد.';
      seen.add(provider);
      input.setCustomValidity(issue);
      input.setAttribute('aria-invalid', String(Boolean(issue)));
      if (issue && !error) error = issue;
      return provider + '=' + select.value;
    });
    mappingRaw.value = entries.join(',');
    $('[data-mapping-error]').textContent = error;
    return !error;
  };
  const reminderInputs = () => $$('[data-wwcb-reminders] input');
  const syncReminders = () => {
    const days = value('validity_days');
    const inputs = reminderInputs();
    const seen = new Set(); let error = '';
    inputs.forEach(input => {
      const n = Number(input.value); let issue = '';
      input.max = Math.max(0, days - 1);
      if (!input.value || !Number.isInteger(n) || n < 1 || n >= days) issue = 'روز یادآوری باید حداقل ۱ و کمتر از مهلت ' + format(days) + ' روزه باشد.';
      else if (seen.has(n)) issue = 'این روز تکراری است؛ برای هر روز فقط یک نوبت بگذارید.';
      seen.add(n); input.setCustomValidity(issue); input.setAttribute('aria-invalid', String(Boolean(issue)));
      const row = input.closest('.wwcb-reminder'); row.dataset.invalid = String(Boolean(issue));
      row.querySelector('[data-reminder-help]').textContent = issue || 'ارسال ' + format(days - n) + ' روز پس از ثبت اعتبار؛ وقتی ' + format(n) + ' روز تا انقضا مانده است.';
      if (issue && !error) error = issue;
    });
    $('[data-reminder-error]').textContent = error;
    $('[data-reminder-empty]').hidden = inputs.length > 0;
    $('[data-reminder-count]').textContent = format(inputs.length) + ' نوبت';
    $('[data-summary-reminders]').textContent = inputs.length ? inputs.map(input => format(Number(input.value))).join('، ') + ' روز مانده' : 'بدون یادآوری';
    const timeline = $('[data-reminder-timeline]'); timeline.replaceChildren();
    const addPoint = (title, description) => { const item = document.createElement('div'); item.className = 'wwcb-timeline-item'; const b = document.createElement('b'); const span = document.createElement('span'); b.textContent = title; span.textContent = description; item.append(b, span); timeline.append(item); };
    addPoint('ثبت اعتبار', 'شروع مهلت ' + format(days) + ' روزه');
    [...new Set(inputs.map(input => Number(input.value)))].filter(n => n > 0 && n < days).sort((a, b) => b - a).forEach(n => addPoint(format(days - n) + ' روز بعد', 'یادآوری: ' + format(n) + ' روز تا انقضا'));
    addPoint(format(days) + ' روز بعد', 'پایان مهلت اعتبار همین خرید');
    return !error;
  };
  const update = () => {
    const rate = value('rate'); const days = value('validity_days'); const step = value('round_step_toman');
    const earned = step > 0 ? Math.ceil((2000000 * rate / 100) / step) * step : 0;
    $('[data-example-amount]').textContent = format(earned) + ' تومان';
    $('[data-example-days]').textContent = format(days) + ' روز';
    $('[data-summary-rate]').textContent = format(rate) + ' درصد';
    $('[data-summary-days]').textContent = format(days) + ' روز';
    $('[data-summary-min]').textContent = format(value('min_spend_toman')) + ' تومان';
    const mode = field('sms_mode').value;
    $$('[data-sms-mode]').forEach(panel => { panel.hidden = panel.dataset.smsMode !== mode; });
    // Inactive mapping controls must not block saving text mode or disabled SMS.
    syncMapping();
    if (!field('sms_enabled').checked || mode !== 'pattern') $$('[data-mapping-rows] input').forEach(input => input.setCustomValidity(''));
    syncReminders();
    $$('[data-preview]').forEach(preview => { preview.textContent = field(preview.dataset.preview).value.replace(/\{(name|amount|balance|expires|order)\}/g, (_, key) => samples[key]); });
    const keyAvailable = field('sms_key').value.trim() || (field('sms_key').dataset.keySaved === '1' && !$('[name="wwcb_settings[clear_key]"]').checked);
    const configured = keyAvailable && field('sms_sender').value.trim() && (mode === 'pattern' ? field('pattern_grant').value.trim() && (!reminderInputs().length || field('pattern_reminder').value.trim()) && mappingRaw.value.trim() && (!$('[data-mapping-error]').textContent || !mappingEnhanced) : field('text_grant').value.trim() && (!reminderInputs().length || field('text_reminder').value.trim()));
    const status = $('[data-sms-status]');
    status.dataset.state = !field('sms_enabled').checked ? 'off' : configured ? 'ready' : 'incomplete';
    status.textContent = !field('sms_enabled').checked ? 'ارسال پیامک خاموش است' : configured ? 'اطلاعات وارد شده؛ اتصال بررسی نشده' : 'تنظیمات پیامک ناقص است';
    $('[data-reminder-sms-note]').hidden = field('sms_enabled').checked && Boolean(configured);
  };
  const dirty = () => { $('[data-save-state]').textContent = 'تغییرات ذخیره نشده'; };
  form.addEventListener('input', () => { dirty(); update(); });
  form.addEventListener('change', () => { dirty(); update(); });
  form.addEventListener('click', event => {
    const button = event.target.closest('button'); if (!button) return;
    if (button.hasAttribute('data-wwcb-add')) {
      const used = new Set(reminderInputs().map(input => Number(input.value)));
      let next = Math.min(3, value('validity_days') - 1);
      while (next >= 1 && used.has(next)) next--;
      if (next < 1) { next = value('validity_days') - 1; while (next >= 1 && used.has(next)) next--; }
      if (next < 1) { $('[data-reminder-error]').textContent = 'برای این مهلت، روز آزاد دیگری باقی نمانده است. ابتدا مهلت اعتبار را بیشتر کنید.'; return; }
      const row = document.createElement('div'); row.className = 'wwcb-reminder';
      row.innerHTML = '<label>وقتی <input type="number" min="1" name="wwcb_settings[reminder_days][]" aria-label="روز باقی‌مانده تا انقضا"> روز تا انقضا مانده</label><button type="button" class="wwcb-remove" data-wwcb-remove>حذف نوبت</button><p class="wwcb-help" data-reminder-help></p>';
      row.querySelector('input').value = next; $('[data-wwcb-reminders]').append(row); dirty(); update(); row.querySelector('input').focus();
    }
    if (button.hasAttribute('data-wwcb-remove')) { const row = button.closest('.wwcb-reminder'); const target = row.nextElementSibling?.querySelector('input') || row.previousElementSibling?.querySelector('input') || $('[data-wwcb-add]'); row.remove(); dirty(); update(); target.focus(); }
    if (button.hasAttribute('data-mapping-add')) { const row = mappingRow('', 'name'); dirty(); update(); row.querySelector('input').focus(); }
    if (button.hasAttribute('data-mapping-remove')) { const row = button.closest('.wwcb-mapping-row'); const target = row.nextElementSibling?.querySelector('input') || row.previousElementSibling?.querySelector('input') || $('[data-mapping-add]'); row.remove(); dirty(); update(); target.focus(); }
    if (button.dataset.token) { const input = field(button.closest('[data-token-target]').dataset.tokenTarget); const start = input.selectionStart; input.setRangeText('{' + button.dataset.token + '}', start, input.selectionEnd, 'end'); dirty(); update(); input.focus(); }
  });
  // Reveal a field's tab before native validation tries to focus a hidden invalid input.
  form.addEventListener('invalid', event => { const panel = event.target.closest('.wwcb-panel'); if (panel) activate(panel.id.replace('wwcb-panel-', '')); }, true);
  form.noValidate = true;
  form.addEventListener('submit', event => {
    update();
    const invalid = [...form.elements].find(input => input.willValidate && !input.validity.valid);
    if (invalid) {
      event.preventDefault();
      const panel = invalid.closest('.wwcb-panel');
      if (panel) activate(panel.id.replace('wwcb-panel-', ''));
      invalid.focus(); invalid.reportValidity();
      return;
    }
    $('[data-save-state]').textContent = 'در حال ذخیره تنظیمات…';
  });
  update();
})();
