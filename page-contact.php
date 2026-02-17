<?php
/**
 * Template Name: Contact
 * Description: Modern interactive contact form
 *
 * @package PinLightning
 * @since 1.0.0
 */
get_header();

$brand = get_theme_mod('pl_brand_name', 'Cheerlives');
$accent = get_theme_mod('pl_accent_primary', '#e84393');
?>

<main class="pl-contact-page">
    <style>
        .pl-contact-page {
            min-height: 100vh;
            background: #fafafa;
            padding: 40px 20px 80px;
        }
        .pl-contact-container {
            max-width: 640px;
            margin: 0 auto;
        }
        .pl-contact-header {
            text-align: center;
            margin-bottom: 40px;
        }
        .pl-contact-header h1 {
            font-size: 32px;
            font-weight: 800;
            color: #111;
            margin: 0 0 10px;
            letter-spacing: -0.5px;
        }
        .pl-contact-header p {
            color: #888;
            font-size: 15px;
            margin: 0;
            line-height: 1.6;
        }
        .pl-contact-card {
            background: #fff;
            border-radius: 20px;
            padding: 36px;
            box-shadow: 0 1px 3px rgba(0,0,0,.04), 0 8px 24px rgba(0,0,0,.04);
            border: 1px solid #f0f0f0;
        }
        .pl-form-group {
            margin-bottom: 20px;
            position: relative;
        }
        .pl-form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #333;
            margin-bottom: 6px;
        }
        .pl-form-group label .required {
            color: <?php echo $accent; ?>;
        }
        .pl-form-input, .pl-form-textarea, .pl-form-select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #eee;
            border-radius: 12px;
            font-size: 14px;
            font-family: inherit;
            color: #333;
            background: #fff;
            transition: border-color 0.2s, box-shadow 0.2s;
            box-sizing: border-box;
            -webkit-appearance: none;
        }
        .pl-form-input:focus, .pl-form-textarea:focus, .pl-form-select:focus {
            outline: none;
            border-color: <?php echo $accent; ?>;
            box-shadow: 0 0 0 4px <?php echo $accent; ?>15;
        }
        .pl-form-input::placeholder, .pl-form-textarea::placeholder {
            color: #bbb;
        }
        .pl-form-textarea {
            resize: vertical;
            min-height: 140px;
            line-height: 1.6;
        }
        .pl-form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        @media (max-width: 540px) {
            .pl-form-row { grid-template-columns: 1fr; }
            .pl-contact-card { padding: 24px; }
            .pl-contact-header h1 { font-size: 26px; }
        }
        .pl-char-count {
            position: absolute;
            right: 12px;
            bottom: -18px;
            font-size: 11px;
            color: #ccc;
            transition: color 0.2s;
        }
        .pl-char-count.warn { color: #f59e0b; }
        .pl-char-count.full { color: #ef4444; }

        /* Submit button */
        .pl-form-submit {
            width: 100%;
            padding: 14px;
            background: <?php echo $accent; ?>;
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
            overflow: hidden;
        }
        .pl-form-submit:hover:not(:disabled) {
            filter: brightness(1.08);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px <?php echo $accent; ?>40;
        }
        .pl-form-submit:active:not(:disabled) {
            transform: translateY(0);
        }
        .pl-form-submit:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }

        /* Validation states */
        .pl-form-group.error .pl-form-input,
        .pl-form-group.error .pl-form-textarea {
            border-color: #ef4444;
        }
        .pl-form-group .pl-error-msg {
            display: none;
            font-size: 12px;
            color: #ef4444;
            margin-top: 4px;
        }
        .pl-form-group.error .pl-error-msg {
            display: block;
        }

        /* Success state */
        .pl-contact-success {
            text-align: center;
            padding: 48px 24px;
        }
        .pl-contact-success .pl-success-icon {
            width: 64px;
            height: 64px;
            background: #dcfce7;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 28px;
        }
        .pl-contact-success h2 {
            font-size: 22px;
            margin: 0 0 8px;
            color: #111;
        }
        .pl-contact-success p {
            color: #888;
            font-size: 14px;
            margin: 0 0 24px;
        }
        .pl-contact-success a {
            color: <?php echo $accent; ?>;
            text-decoration: none;
            font-weight: 600;
        }

        /* Honeypot */
        .pl-hp { position: absolute; left: -9999px; }

        /* Info cards */
        .pl-contact-info {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-top: 24px;
        }
        @media (max-width: 540px) {
            .pl-contact-info { grid-template-columns: 1fr; }
        }
        .pl-info-card {
            text-align: center;
            padding: 16px;
            background: #f9f9f9;
            border-radius: 12px;
        }
        .pl-info-card .pl-info-icon { font-size: 20px; margin-bottom: 6px; }
        .pl-info-card .pl-info-label { font-size: 12px; color: #888; }
        .pl-info-card .pl-info-value { font-size: 13px; font-weight: 600; color: #333; }
    </style>

    <div class="pl-contact-container">
        <div class="pl-contact-header">
            <h1>Get in Touch</h1>
            <p>Have a question, suggestion, or collaboration idea?<br>We'd love to hear from you!</p>
        </div>

        <div class="pl-contact-card" id="plContactCard">
            <form id="plContactForm" novalidate>
                <div class="pl-form-row">
                    <div class="pl-form-group">
                        <label>Name <span class="required">*</span></label>
                        <input type="text" class="pl-form-input" name="name" placeholder="Your name" required maxlength="100" autocomplete="name" />
                        <span class="pl-error-msg">Please enter your name</span>
                    </div>
                    <div class="pl-form-group">
                        <label>Email <span class="required">*</span></label>
                        <input type="email" class="pl-form-input" name="email" placeholder="your@email.com" required autocomplete="email" />
                        <span class="pl-error-msg">Please enter a valid email</span>
                    </div>
                </div>

                <div class="pl-form-group">
                    <label>Subject</label>
                    <select class="pl-form-select" name="subject">
                        <option value="">Choose a topic...</option>
                        <option value="General Inquiry">General Inquiry</option>
                        <option value="Content Suggestion">Content Suggestion</option>
                        <option value="Collaboration">Collaboration / Partnership</option>
                        <option value="Copyright / DMCA">Copyright / DMCA</option>
                        <option value="Advertising">Advertising</option>
                        <option value="Bug Report">Bug Report</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="pl-form-group">
                    <label>Message <span class="required">*</span></label>
                    <textarea class="pl-form-textarea" name="message" placeholder="What's on your mind?" required maxlength="2000"></textarea>
                    <span class="pl-char-count" id="plCharCount">0 / 2000</span>
                    <span class="pl-error-msg">Message must be at least 10 characters</span>
                </div>

                <!-- Honeypot -->
                <div class="pl-hp">
                    <input type="text" name="website_url" tabindex="-1" autocomplete="off" />
                </div>

                <button type="submit" class="pl-form-submit" id="plSubmitBtn">
                    Send Message
                </button>
            </form>
        </div>

        <!-- Info Cards -->
        <div class="pl-contact-info">
            <div class="pl-info-card">
                <div class="pl-info-icon">&#x23F1;</div>
                <div class="pl-info-value">Within 24 hours</div>
                <div class="pl-info-label">Response time</div>
            </div>
            <div class="pl-info-card">
                <div class="pl-info-icon">&#x1F4CD;</div>
                <div class="pl-info-value"><?php echo esc_html($brand); ?></div>
                <div class="pl-info-label">Fashion & Lifestyle Blog</div>
            </div>
            <div class="pl-info-card">
                <div class="pl-info-icon">&#x1F4CC;</div>
                <div class="pl-info-value">Pinterest</div>
                <div class="pl-info-label">Follow us for more</div>
            </div>
        </div>
    </div>

    <script>
    (function() {
        var form = document.getElementById('plContactForm');
        var card = document.getElementById('plContactCard');
        var btn = document.getElementById('plSubmitBtn');
        var charCount = document.getElementById('plCharCount');
        var textarea = form.querySelector('textarea[name="message"]');

        // Character counter
        textarea.addEventListener('input', function() {
            var len = this.value.length;
            charCount.textContent = len + ' / 2000';
            charCount.className = 'pl-char-count' + (len > 1800 ? ' full' : len > 1500 ? ' warn' : '');
        });

        // Real-time validation
        form.querySelectorAll('.pl-form-input, .pl-form-textarea').forEach(function(input) {
            input.addEventListener('blur', function() {
                validateField(this);
            });
            input.addEventListener('input', function() {
                if (this.closest('.pl-form-group').classList.contains('error')) {
                    validateField(this);
                }
            });
        });

        function validateField(input) {
            var group = input.closest('.pl-form-group');
            var valid = true;

            if (input.name === 'name') valid = input.value.trim().length >= 1;
            if (input.name === 'email') valid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.value);
            if (input.name === 'message') valid = input.value.trim().length >= 10;

            group.classList.toggle('error', !valid);
            return valid;
        }

        // Submit
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            // Validate all
            var valid = true;
            form.querySelectorAll('[required]').forEach(function(f) {
                if (!validateField(f)) valid = false;
            });
            if (!valid) return;

            btn.disabled = true;
            btn.textContent = 'Sending...';

            // Detect browser
            var ua = navigator.userAgent;
            var browser = 'Unknown';
            if (ua.indexOf('Chrome') > -1 && ua.indexOf('Edg') === -1) browser = 'Chrome';
            else if (ua.indexOf('Safari') > -1 && ua.indexOf('Chrome') === -1) browser = 'Safari';
            else if (ua.indexOf('Firefox') > -1) browser = 'Firefox';
            else if (ua.indexOf('Edg') > -1) browser = 'Edge';

            var vid = '';
            try { vid = localStorage.getItem('plt_vid') || ''; } catch(ex) {}

            var data = {
                name: form.name.value.trim(),
                email: form.email.value.trim(),
                subject: form.subject.value,
                message: form.message.value.trim(),
                website_url: form.website_url.value, // honeypot
                page_url: location.href,
                visitor_id: vid,
                device: window.innerWidth < 768 ? 'mobile' : (window.innerWidth < 1024 ? 'tablet' : 'desktop'),
                browser: browser
            };

            fetch('<?php echo esc_url(rest_url('pl/v1/contact')); ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data)
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success) {
                    card.innerHTML = '<div class="pl-contact-success">'
                        + '<div class="pl-success-icon">&#x2709;&#xFE0F;</div>'
                        + '<h2>Message Sent!</h2>'
                        + '<p>Thank you for reaching out. We\'ll get back to you within 24 hours.</p>'
                        + '<a href="<?php echo esc_url(home_url('/')); ?>">&larr; Back to <?php echo esc_js($brand); ?></a>'
                        + '</div>';
                    window.scrollTo({top: 0, behavior: 'smooth'});
                } else {
                    btn.textContent = res.error || 'Error \u2014 try again';
                    btn.disabled = false;
                    setTimeout(function() { btn.textContent = 'Send Message'; }, 3000);
                }
            })
            .catch(function() {
                btn.textContent = 'Error \u2014 try again';
                btn.disabled = false;
                setTimeout(function() { btn.textContent = 'Send Message'; }, 3000);
            });
        });
    })();
    </script>
</main>

<?php get_footer(); ?>
