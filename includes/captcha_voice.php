<?php
/**
 * Speech for the audio CAPTCHA.
 *
 * Uses the speech engine built into Windows (SAPI, through System.Speech) to
 * render one short WAV per character, cached on disk. The first challenge that
 * needs a character pays for synthesising it; after that it is a file read, so
 * the endpoint does not shell out on every request.
 *
 * Per character rather than per challenge because a challenge is five
 * characters drawn from a set of thirty-one: caching the parts means the cache
 * saturates after a few dozen challenges, where caching whole phrases never
 * would.
 *
 * Nothing from the request reaches the shell. The only strings passed to
 * PowerShell are single characters from CAPTCHA_VOICE_ALPHABET, checked
 * against it immediately before the call.
 */
require_once __DIR__ . '/functions.php';

const CAPTCHA_VOICE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
const CAPTCHA_VOICE_RATE = 16000;          // samples/second, mono 16-bit

/** Where the per-character clips live. Runtime cache, not source. */
function captcha_voice_dir(): string
{
    return __DIR__ . '/../storage/captcha_voice';
}

/**
 * Is a speech engine actually usable here?
 *
 * Answered by trying it once and remembering the answer, because "powershell
 * exists" and "a voice is installed and can write a file" are different
 * questions and only the second one matters. The marker file keeps a failed
 * probe from being repeated on every request.
 */
function captcha_voice_available(): bool
{
    static $known = null;
    if ($known !== null) {
        return $known;
    }

    $dir = captcha_voice_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return $known = false;
    }

    $marker = $dir . '/.engine';
    if (is_file($marker)) {
        return $known = (trim((string)file_get_contents($marker)) === 'ok');
    }

    // Probing is just asking for the first character; if that lands, the
    // engine works.
    $ok = captcha_voice_render(['A']) && is_file(captcha_voice_path('A'));
    @file_put_contents($marker, $ok ? 'ok' : 'none');

    return $known = $ok;
}

/** Cache path for one character's clip. */
function captcha_voice_path(string $char): string
{
    return captcha_voice_dir() . '/' . $char . '.wav';
}

/**
 * Synthesise any of $chars that are not cached yet, in one PowerShell call.
 *
 * One call rather than one per character: starting PowerShell costs far more
 * than speaking a letter, and a five-character challenge would otherwise pay
 * that five times over.
 */
function captcha_voice_render(array $chars): bool
{
    $dir = captcha_voice_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    $wanted = [];
    foreach ($chars as $char) {
        // The gate: only characters from our own alphabet are ever named to
        // the shell, and they are single characters with no quoting surface.
        if (strlen($char) === 1 && strpos(CAPTCHA_VOICE_ALPHABET, $char) !== false
            && !is_file(captcha_voice_path($char))) {
            $wanted[$char] = true;
        }
    }
    if (!$wanted) {
        return true;        // everything needed is already cached
    }

    $list = implode(',', array_map(
        static fn($c) => "'" . $c . "'",
        array_keys($wanted)
    ));
    $dirArg = str_replace("'", "''", realpath($dir) ?: $dir);

    /* Spoken slowly and one character at a time. The rate is well below
       default because a CAPTCHA is dictation, not prose - the listener is
       writing each character down. */
    $script = <<<PS
\$ErrorActionPreference = 'Stop'
try {
  Add-Type -AssemblyName System.Speech
  \$synth = New-Object System.Speech.Synthesis.SpeechSynthesizer
  \$voice = \$synth.GetInstalledVoices() | Where-Object { \$_.Enabled -and \$_.VoiceInfo.Culture.Name -like 'en*' } | Select-Object -First 1
  if (\$voice) { \$synth.SelectVoice(\$voice.VoiceInfo.Name) }
  \$synth.Rate = -2
  \$fmt = New-Object System.Speech.AudioFormat.SpeechAudioFormatInfo(16000, [System.Speech.AudioFormat.AudioBitsPerSample]::Sixteen, [System.Speech.AudioFormat.AudioChannel]::Mono)
  foreach (\$c in @($list)) {
    \$path = Join-Path '$dirArg' (\$c + '.wav')
    \$synth.SetOutputToWaveFile(\$path, \$fmt)
    \$synth.Speak(\$c)
    \$synth.SetOutputToNull()
  }
  \$synth.Dispose()
  'done'
} catch { 'failed: ' + \$_.Exception.Message; exit 1 }
PS;

    $tmp = $dir . '/.render.ps1';
    if (@file_put_contents($tmp, $script) === false) {
        return false;
    }

    $cmd = 'powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File '
        . escapeshellarg($tmp);

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        @unlink($tmp);
        return false;
    }

    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    $code = proc_close($proc);
    @unlink($tmp);

    if ($code !== 0) {
        error_log('captcha_voice: synthesis failed - ' . trim($out));
        return false;
    }

    // Every character asked for has to exist now, or the clip would have a
    // silent hole where a letter should be.
    foreach (array_keys($wanted) as $char) {
        if (!is_file(captcha_voice_path($char))) {
            return false;
        }
    }

    return true;
}

/** The PCM payload of a cached clip, without its RIFF header. */
function captcha_voice_pcm(string $char): ?string
{
    $path = captcha_voice_path($char);
    if (!is_file($path)) {
        return null;
    }
    $raw = (string)file_get_contents($path);
    $at = strpos($raw, 'data');
    if ($at === false) {
        return null;
    }

    return substr($raw, $at + 8);
}

/**
 * The whole challenge spoken, as raw PCM, or null when speech is unavailable.
 * The caller decides what to do about that — this never substitutes something
 * else and calls it speech.
 */
function captcha_voice_pcm_for(string $code): ?string
{
    $chars = str_split($code);
    if (!captcha_voice_available() || !captcha_voice_render($chars)) {
        return null;
    }

    $gap = str_repeat("\0\0", (int)(CAPTCHA_VOICE_RATE * 0.45));   // between characters
    $lead = str_repeat("\0\0", (int)(CAPTCHA_VOICE_RATE * 0.30));

    $parts = [];
    foreach ($chars as $char) {
        $pcm = captcha_voice_pcm($char);
        if ($pcm === null) {
            return null;        // a missing letter would be a silent gap
        }
        $parts[] = $pcm;
    }

    return $lead . implode($gap, $parts) . $lead;
}

/** Wrap raw PCM in a RIFF header. */
function captcha_wav(string $pcm, int $rate = CAPTCHA_VOICE_RATE): string
{
    $size = strlen($pcm);

    return 'RIFF' . pack('V', 36 + $size) . 'WAVE'
        . 'fmt ' . pack('V', 16) . pack('v', 1) . pack('v', 1)
        . pack('V', $rate) . pack('V', $rate * 2) . pack('v', 2) . pack('v', 16)
        . 'data' . pack('V', $size) . $pcm;
}
