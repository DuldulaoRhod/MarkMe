/* MarkMe - session expiry.
   
   A companion to the <meta http-equiv="refresh"> the page already carries.
   The meta tag is what guarantees the reload; this catches the case it
   handles poorly - a machine that was asleep or a tab that was backgrounded,
   where the refresh may not have fired on time and the page would otherwise
   be shown again still looking signed in.
   
   Deliberately its own file. This used to sit at the end of script.js, where
   any unrelated error earlier in two thousand lines would stop it running.
   
   It decides nothing: it reloads the current page as a GET and the server's
   own check gives the real answer. If the session was extended elsewhere, the
   page simply renders again with a fresh countdown.
*/
(() => {
  const left = Number(document.body?.dataset?.sessionLeft);
  if (!Number.isFinite(left) || left <= 0) return;

  const expiresAt = Date.now() + left * 1000;
  let fired = false;

  const check = () => {
    if (fired || Date.now() < expiresAt) return;
    fired = true;
    /* A GET of where we already are. Re-posting a form on expiry would be
       worse than the problem this solves. */
    window.location.href = window.location.pathname + window.location.search;
  };

  window.setTimeout(check, left * 1000 + 500);
  document.addEventListener("visibilitychange", () => {
    if (!document.hidden) check();
  });
  window.addEventListener("focus", check);
  window.addEventListener("pageshow", check);
})();
