(function () {
  try {
    var script = document.currentScript || (function () {
      var scripts = document.getElementsByTagName('script');
      return scripts[scripts.length - 1];
    })();

    var u = new URL(script.src);
    var basePath = u.pathname.replace(/\/[^\/]*$/, "");
    var endpoint = u.origin + basePath + "/api/event.php";

    var token = "";
    try {
      token = script.getAttribute("data-site") || "";
    } catch (e) {}

    // Per-page session id (used for heartbeat + duration)
    var sid = "";
    try {
      if (window.crypto && crypto.getRandomValues) {
        var arr = new Uint8Array(16);
        crypto.getRandomValues(arr);
        sid = Array.from(arr).map(function (b) {
          return b.toString(16).padStart(2, "0");
        }).join("");
      } else {
        sid = (Math.random().toString(16).slice(2) + Date.now().toString(16)).slice(0, 32);
      }
    } catch (e) {
      sid = (Math.random().toString(16).slice(2) + Date.now().toString(16)).slice(0, 32);
    }

    // Visitor + visit-session ids (used for bounce rate)
    // Stored on the *tracked website's* storage (not the analytics host), so it works cross-domain.
    var vid = "";
    var vsid = "";
    var VISITOR_KEY = "sa_vid";
    var SESSION_KEY = "sa_vsid";
    var SESSION_TS_KEY = "sa_vsid_ts";
    var SESSION_TIMEOUT_MS = 30 * 60 * 1000; // 30 minutes

    function rand32() {
      try {
        if (window.crypto && crypto.getRandomValues) {
          var a = new Uint8Array(16);
          crypto.getRandomValues(a);
          return Array.from(a)
            .map(function (b) { return b.toString(16).padStart(2, "0"); })
            .join("");
        }
      } catch (e) {}
      return (Math.random().toString(16).slice(2) + Date.now().toString(16)).slice(0, 32);
    }

    function getStored(key) {
      try {
        return localStorage.getItem(key) || "";
      } catch (e) {
        return "";
      }
    }

    function setStored(key, value) {
      try {
        localStorage.setItem(key, value);
      } catch (e) {}
    }

    (function initVisitorAndSession() {
      vid = getStored(VISITOR_KEY);
      if (!vid) {
        vid = rand32();
        setStored(VISITOR_KEY, vid);
      }

      var lastTs = parseInt(getStored(SESSION_TS_KEY) || "0", 10) || 0;
      var now = Date.now();
      var existingVsid = getStored(SESSION_KEY);

      if (!existingVsid || (now - lastTs) > SESSION_TIMEOUT_MS) {
        vsid = rand32();
        setStored(SESSION_KEY, vsid);
      } else {
        vsid = existingVsid;
      }

      setStored(SESSION_TS_KEY, String(now));
    })();

    function send(type) {
      fetch(endpoint, {
        method: "POST",
        headers: { "Content-Type": "text/plain;charset=UTF-8" },
        body: JSON.stringify({
          token: token,
          type: type,
          sid: sid,
          vid: vid,
          vsid: vsid,
          site: location.hostname,
          page: location.pathname,
          ref: document.referrer || "Direct"
        })
      }).catch(function () { });
    }

    // Initial pageview
    send("pageview");

    // Heartbeat: keep "Active now" accurate and allow duration estimation
    setTimeout(function(){ try { send('ping'); } catch(e) {} }, 1000);
    var maxMinutes = 5;
    var intervalMs = 30000; // 30s
    var maxPings = Math.floor((maxMinutes * 60 * 1000) / intervalMs);
    var pings = 0;

    var timer = setInterval(function () {
      pings++;
      send("ping");
      if (pings >= maxPings) clearInterval(timer);
    }, intervalMs);

    // Send a final ping when leaving (best effort)
    document.addEventListener('visibilitychange', function(){
      try { if (document.visibilityState === 'hidden') send('ping'); } catch(e) {}
    });

    window.addEventListener("beforeunload", function () {
      try { send("ping"); } catch (e) { }
    });
  } catch (e) { }
})();