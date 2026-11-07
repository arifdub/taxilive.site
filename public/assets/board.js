/* Noticeboard: pinned notices, posts grouped by category, and a pop-up details view. */
(function () {
  "use strict";
  var API = "api/posts.php";
  var GROUPS = [
    { key: "notice", label: "📰 Notices", tag: "📰 Notice" },
    { key: "rent", label: "🚕 For rent", tag: "🚕 For rent" },
    { key: "sale", label: "🏷️ For sale", tag: "🏷️ For sale" },
    { key: "service", label: "🔧 Services", tag: "🔧 Service" },
    { key: "wanted", label: "🔎 Wanted", tag: "🔎 Wanted" },
    { key: "other", label: "📣 Other ads", tag: "📣 Advert" }
  ];
  var BY_KEY = {}; GROUPS.forEach(function (g) { BY_KEY[g.key] = g; });
  var list = document.getElementById("posts");
  var emptyBox = document.getElementById("boardEmpty");
  var errBox = document.getElementById("boardError");
  var chips = document.getElementById("boardChips");
  var posts = [];
  var filter = "all";

  /* ---------- helpers ---------- */
  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }
  function ago(ts) {
    var s = Math.max(0, Date.now() / 1000 - ts);
    if (s < 60) return "just now";
    if (s < 3600) return Math.floor(s / 60) + " min ago";
    if (s < 86400) return Math.floor(s / 3600) + " h ago";
    var d = Math.floor(s / 86400);
    return d === 1 ? "yesterday" : d + " days ago";
  }
  function intl(num) {
    var d = num.replace(/\D/g, "");
    if (num.trim().charAt(0) === "+") return d;
    return d.charAt(0) === "0" ? "353" + d.slice(1) : d;
  }
  function toast(msg) {
    var t = document.getElementById("toast");
    t.textContent = msg; t.classList.add("is-on");
    clearTimeout(toast._t); toast._t = setTimeout(function () { t.classList.remove("is-on"); }, 2500);
  }
  function groupOf(p) {
    if (p.type === "notice") return "notice";
    if (p.type === "wanted") return "wanted";
    return BY_KEY[p.category] ? p.category : "other";
  }
  function postUrl(p) { return location.origin + location.pathname + "#post-" + p.id; }
  function shareText(p) {
    return (p.type === "notice" ? "📰 " : "📣 ") + p.title + (p.price ? " – " + p.price : "") + "\n" +
      p.body.slice(0, 200) + (p.body.length > 200 ? "…" : "") + (p.phone ? "\n📞 " + p.phone : "") + "\n\n" + postUrl(p);
  }
  function contactButtons(p, box, small) {
    var cls = small ? "btn btn--sm " : "btn ";
    if (p.phone) {
      var call = el("a", cls + "btn--call", "📞 Call");
      call.href = "tel:+" + intl(p.phone);
      box.appendChild(call);
      var wa = el("a", cls + "btn--wa", "WhatsApp");
      wa.href = "https://wa.me/" + intl(p.phone) + "?text=" + encodeURIComponent("Hi, about your Taxi Live post: " + p.title);
      wa.target = "_blank"; wa.rel = "noopener";
      box.appendChild(wa);
    }
  }

  /* ---------- data ---------- */
  function load() {
    fetch(API + "?action=list", { cache: "no-store" })
      .then(function (r) { if (!r.ok) throw 0; return r.json(); })
      .then(function (j) { posts = j.posts || []; errBox.hidden = true; render(); openFromHash(); })
      .catch(function () { posts = []; render(); errBox.hidden = false; });
  }

  /* ---------- board ---------- */
  function renderChips(counts) {
    chips.innerHTML = "";
    var all = [{ key: "all", label: "⭐ All" }].concat(GROUPS);
    all.forEach(function (g) {
      var n = g.key === "all" ? counts.total : (counts[g.key] || 0);
      if (g.key !== "all" && !n) return;
      var b = el("button", "chip");
      b.type = "button"; b.setAttribute("role", "tab"); b.dataset.type = g.key;
      b.setAttribute("aria-selected", g.key === filter ? "true" : "false");
      b.appendChild(document.createTextNode(g.label + " "));
      b.appendChild(el("span", "chip__count", String(n)));
      chips.appendChild(b);
    });
  }

  function card(p) {
    var g = BY_KEY[groupOf(p)];
    var c = el("article", "post post--" + (p.type === "notice" ? "notice" : "advert"));
    c.tabIndex = 0;
    c.setAttribute("aria-label", p.title + " – view details");
    if (p.image) {
      var fig = el("div", "post__media");
      var img = el("img", "post__img"); img.src = p.image; img.alt = ""; img.loading = "lazy";
      fig.appendChild(img);
      c.appendChild(fig);
    }
    var body = el("div", "post__body");
    var meta = el("div", "post__meta");
    meta.appendChild(el("span", "tag tag--" + g.key, g.tag));
    meta.appendChild(el("span", "post__time", ago(p.created)));
    body.appendChild(meta);
    body.appendChild(el("h3", "post__title post__title--clamp", p.title));
    if (p.price) body.appendChild(el("p", "post__price", p.price));
    body.appendChild(el("p", "post__text post__text--short", p.body));
    body.appendChild(el("p", "post__who", "👤 " + p.name + (p.area ? "  ·  📍 " + p.area : "")));
    var act = el("div", "post__actions");
    contactButtons(p, act, true);
    var more = el("button", "btn btn--sm btn--more", "View more →");
    more.type = "button";
    act.appendChild(more);
    body.appendChild(act);
    c.appendChild(body);
    c.addEventListener("click", function (e) { if (!e.target.closest("a")) openPost(p); });
    c.addEventListener("keydown", function (e) { if (e.key === "Enter" && e.target === c) openPost(p); });
    return c;
  }

  function pinnedCard(p) {
    var c = el("article", "post post--notice post--pinned");
    var body = el("div", "post__body");
    var meta = el("div", "post__meta");
    meta.appendChild(el("span", "tag tag--pinned", "📌 Pinned notice"));
    if (p.deadline) meta.appendChild(el("span", "post__deadline", "📅 " + p.deadline));
    body.appendChild(meta);
    body.appendChild(el("h3", "post__title", p.title));
    body.appendChild(el("p", "post__text post__text--short", p.body));
    body.appendChild(el("p", "post__who", "👤 " + p.name + (p.phone ? "  ·  📞 " + p.phone : "")));
    var act = el("div", "post__actions");
    if (p.link_url) {
      var go = el("a", "btn btn--sm btn--primary", (p.link_label || "Open link") + " ↗");
      go.href = p.link_url; go.target = "_blank"; go.rel = "noopener";
      act.appendChild(go);
    }
    var more = el("button", "btn btn--sm btn--more", "Read more →");
    more.type = "button";
    more.addEventListener("click", function () { openPost(p); });
    act.appendChild(more);
    body.appendChild(act);
    c.appendChild(body);
    return c;
  }

  function render() {
    list.innerHTML = "";
    var pinned = posts.filter(function (p) { return p.pinned; });
    var normal = posts.filter(function (p) { return !p.pinned; });
    var counts = { total: normal.length };
    normal.forEach(function (p) { var k = groupOf(p); counts[k] = (counts[k] || 0) + 1; });
    if (filter !== "all" && !counts[filter]) filter = "all";
    renderChips(counts);

    if (filter === "all" || filter === "notice") pinned.forEach(function (p) { list.appendChild(pinnedCard(p)); });

    GROUPS.forEach(function (g) {
      if (filter !== "all" && filter !== g.key) return;
      var items = normal.filter(function (p) { return groupOf(p) === g.key; });
      if (!items.length) return;
      var sec = el("section", "stack");
      var head = el("div", "stack__head");
      head.appendChild(el("h3", "stack__title", g.label));
      head.appendChild(el("span", "stack__count", items.length + (items.length === 1 ? " post" : " posts")));
      sec.appendChild(head);
      var grid = el("div", "posts__grid");
      items.forEach(function (p) { grid.appendChild(card(p)); });
      sec.appendChild(grid);
      list.appendChild(sec);
    });
    emptyBox.hidden = posts.length !== 0;
  }

  chips.addEventListener("click", function (e) {
    var b = e.target.closest(".chip");
    if (!b) return;
    filter = b.dataset.type;
    render();
  });

  /* ---------- pop-up details ---------- */
  var modal = el("dialog", "pmodal");
  modal.setAttribute("aria-label", "Post details");
  document.body.appendChild(modal);
  var openId = null;

  function openPost(p, fromHash) {
    var g = BY_KEY[groupOf(p)];
    modal.innerHTML = "";
    var box = el("div", "pmodal__box");
    var close = el("button", "pmodal__close", "✕");
    close.type = "button"; close.setAttribute("aria-label", "Close");
    close.addEventListener("click", closePost);
    box.appendChild(close);

    if (p.image) {
      var a = el("a", "pmodal__media");
      a.href = p.image; a.target = "_blank"; a.rel = "noopener"; a.title = "Open full-size photo";
      var img = el("img", "pmodal__img"); img.src = p.image; img.alt = p.title;
      a.appendChild(img);
      a.appendChild(el("span", "pmodal__zoom", "⤢ Full size"));
      box.appendChild(a);
    }
    var body = el("div", "pmodal__body");
    var meta = el("div", "post__meta");
    meta.appendChild(el("span", "tag " + (p.pinned ? "tag--pinned" : "tag--" + g.key), p.pinned ? "📌 Pinned notice" : g.tag));
    meta.appendChild(el("span", "post__time", p.deadline ? "📅 " + p.deadline : "Posted " + ago(p.created)));
    body.appendChild(meta);
    body.appendChild(el("h2", "pmodal__title", p.title));
    if (p.price) body.appendChild(el("p", "post__price pmodal__price", p.price));
    body.appendChild(el("p", "pmodal__text", p.body));

    var who = el("dl", "pmodal__facts");
    [["Posted by", p.name], ["Area", p.area], ["Phone", p.phone],
     ["Posted", new Date(p.created * 1000).toLocaleDateString("en-IE", { weekday: "short", day: "numeric", month: "long" })]]
      .forEach(function (f) { if (f[1]) { who.appendChild(el("dt", "", f[0])); who.appendChild(el("dd", "", f[1])); } });
    body.appendChild(who);

    var act = el("div", "pmodal__actions");
    if (p.link_url) {
      var go = el("a", "btn btn--primary", (p.link_label || "Open link") + " ↗");
      go.href = p.link_url; go.target = "_blank"; go.rel = "noopener";
      act.appendChild(go);
    }
    contactButtons(p, act, false);
    var share = el("a", "btn btn--ghost-dark", "↗ Share on WhatsApp");
    share.href = "https://wa.me/?text=" + encodeURIComponent(shareText(p));
    share.target = "_blank"; share.rel = "noopener";
    act.appendChild(share);
    body.appendChild(act);

    var foot = el("div", "pmodal__foot");
    var copy = el("button", "linkbtn", "🔗 Copy link");
    copy.type = "button";
    copy.addEventListener("click", function () {
      if (navigator.clipboard) navigator.clipboard.writeText(postUrl(p)).then(function () { toast("Link copied"); }, function () { toast(postUrl(p)); });
      else toast(postUrl(p));
    });
    foot.appendChild(copy);
    if (!p.pinned) {
      var rep = el("button", "linkbtn", "⚑ Report");
      rep.type = "button";
      rep.addEventListener("click", function () {
        if (!confirm("Report this post as spam or inappropriate?")) return;
        var fd = new FormData(); fd.append("action", "report"); fd.append("id", p.id);
        fetch(API, { method: "POST", body: fd }).then(function () { toast("Thanks – post reported"); rep.disabled = true; rep.textContent = "Reported"; });
      });
      foot.appendChild(rep);
    }
    body.appendChild(foot);
    box.appendChild(body);
    modal.appendChild(box);

    if (modal.showModal) { if (!modal.open) modal.showModal(); } else modal.setAttribute("open", "");
    document.documentElement.classList.add("has-modal");
    box.scrollTop = 0;
    openId = p.id;
    if (!fromHash && location.hash !== "#post-" + p.id) history.pushState({ post: p.id }, "", "#post-" + p.id);
  }

  function hide() {
    if (modal.close && modal.open) modal.close(); else modal.removeAttribute("open");
    document.documentElement.classList.remove("has-modal");
    openId = null;
  }
  function closePost() {
    if (history.state && history.state.post) history.back();   // popstate hides it
    else { hide(); if (/^#post-/.test(location.hash)) history.replaceState(null, "", location.pathname + location.search + "#board"); }
  }
  modal.addEventListener("click", function (e) { if (e.target === modal) closePost(); });
  modal.addEventListener("cancel", function (e) { e.preventDefault(); closePost(); });
  window.addEventListener("popstate", function () {
    var m = /^#post-(.+)$/.exec(location.hash);
    if (m) { var p = find(decodeURIComponent(m[1])); if (p && openId !== p.id) openPost(p, true); }
    else if (openId) hide();
  });
  function find(id) { return posts.filter(function (p) { return p.id === id; })[0]; }
  function openFromHash() {
    var m = /^#post-(.+)$/.exec(location.hash);
    if (!m) return;
    var p = find(decodeURIComponent(m[1]));
    if (p) { document.getElementById("board").scrollIntoView(); openPost(p, true); }
  }

  load();
})();
