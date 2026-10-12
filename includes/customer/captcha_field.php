<?php
/**
 * The CAPTCHA control, shared by the sign-in and registration forms.
 *
 * One copy, because two would drift: the login form would quietly keep an old
 * field name, or miss the audio button, and only one of them would really be
 * protected. Whichever mechanism is in force is decided by captcha_mode(), so
 * neither form has to know which.
 *
 * Nothing here carries the answer. The image and the audio are separate
 * requests that read the challenge from the session; the page itself only
 * says where to fetch them.
 *
 * Expects $captchaId — a unique id per form, since both forms exist in the
 * same document and a repeated id would break the labels.
 */
$captchaId = $captchaId ?? 'captcha';
?>
<?php if (captcha_mode() === 'recaptcha'): ?>
    <div class="g-recaptcha" style="margin-top:var(--s-5)"
         data-sitekey="<?= e(RECAPTCHA_SITE_KEY) ?>"></div>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
<?php elseif (captcha_mode() === 'local'): ?>
    <fieldset class="captcha-block" data-captcha>
        <legend class="form-label">Confirm you are human</legend>

        <div class="captcha-row">
            <img src="<?= BASE_URL ?>/auth/captcha_image.php"
                 alt="A distorted five-character code. Use the play button to hear it instead."
                 class="captcha-img" width="190" height="60" data-captcha-img>

            <div class="captcha-tools">
                <button type="button" class="btn-icon captcha-reload" data-captcha-reload
                        aria-label="Get a different code">
                    <?= icon('refresh', 17) ?>
                </button>
                <?php /* Real speech, read from the challenge already in the
                         session by auth/captcha_audio.php - not a button that
                         does nothing. It speaks the same code as the picture. */ ?>
                <button type="button" class="btn-icon captcha-audio" data-captcha-audio
                        aria-label="Hear the code read aloud">
                    <?= icon('volume', 17) ?>
                </button>
            </div>
        </div>

        <div class="captcha-field">
            <label class="form-label" for="<?= e($captchaId) ?>">Type the characters</label>
            <input type="text" id="<?= e($captchaId) ?>" name="captcha_answer"
                   class="form-control" autocomplete="off" spellcheck="false"
                   autocapitalize="characters" maxlength="8" required
                   aria-describedby="<?= e($captchaId) ?>Help"
                   style="text-transform:uppercase;letter-spacing:.18em">
            <p class="form-text" id="<?= e($captchaId) ?>Help">
                Letters and numbers only, not case sensitive. Use
                <span class="captcha-inline-icon"><?= icon('refresh', 13) ?></span> for a different
                code or <span class="captcha-inline-icon"><?= icon('volume', 13) ?></span> to hear
                it read aloud.
            </p>
            <p class="captcha-status" data-captcha-status role="status" aria-live="polite"></p>
        </div>
    </fieldset>
<?php endif; ?>
