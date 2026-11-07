(function () {
  "use strict";
  var API = "api/posts.php";
  var LABEL = { advert: "📣 Advert", notice: "📰 Notice" };
  var CAT = { rent: "🚕 For rent", sale: "🏷️ For sale", wanted: "🔎 Wanted", service: "🔧 Service", other: "📣 Advert" };
  var list = document.getElementById("posts");
  var emptyBox = document.getElementById("boardEmpty");
  var errBox = document.getElementById("boardError");
  var chips = document.getElementById("boardChips");
  var posts = [];
  var fromDb = false;
  var filter = "all";

  function mine() {
    try { return JSON.parse(localStorage.getItem("tl_mine") || "{}"); } catch (e) { return {}; }
  }
  function setMine(m) {
    try { localStorage.setItem("tl_mine", JSON.stringify(m)); } catch (e) {}
  }
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
    t.textContent = msg;
    t.classList.add("is-on");
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { t.classList.remove("is-on"); }, 2500);
  }
  function post(data) {
    return fetch(API, { method: "POST", body: data }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) throw new Error(j.error || "Something went wrong");
        return j;
      });
    });
  }

  function load() {
    fetch(API + "?action=list", { cache: "no-store" })
      .then(function (r) { if (!r.ok) throw 0; return r.json(); })
      .then(function (j) { posts = j.posts || []; fromDb = !!j.db; errBox.hidden = true; render(); })
      .catch(function () { posts = []; render(); errBox.hidden = false; });
  }

  function renderPinned(p) {
    var card = el("article", "post post--notice post--pinned");
    var body = el("div", "post__body");
    var meta = el("div", "post__meta");
    meta.appendChild(el("span", "tag tag--pinned", "📌 Pinned notice"));
    if (p.deadline) meta.appendChild(el("span", "post__deadline", "📅 " + p.deadline));
    body.appendChild(meta);
    body.appendChild(el("h3", "post__title", p.title));
    var text = el("p", "post__text post__text--clamp", p.body);
    body.appendChild(text);
    var more = el("button", "linkbtn linkbtn--more", "Read more ▾");
    more.type = "button";
    more.addEventListener("click", function () {
      var open = text.classList.toggle("is-open");
      more.textContent = open ? "Show less ▴" : "Read more ▾";
    });
    body.appendChild(more);
    body.appendChild(el("p", "post__who", "👤 " + p.name + (p.phone ? "  ·  📞 " + p.phone : "")));
    var act = el("div", "post__actions");
    if (p.link) {
      var go = el("a", "btn btn--sm btn--primary", p.link.label + " ↗");
      go.href = p.link.url; go.target = "_blank"; go.rel = "noopener";
      act.appendChild(go);
    }
    if (p.phone) {
      var call = el("a", "btn btn--sm btn--call", "📞 Call");
      call.href = "tel:+" + intl(p.phone);
      act.appendChild(call);
    }
    var share = el("a", "btn btn--sm btn--wa", "Share on WhatsApp");
    share.href = "https://wa.me/?text=" + encodeURIComponent(
      p.title + "\n\n" + p.body + "\n\n📅 " + (p.deadline || "") + "\n" + (p.link ? p.link.url : ""));
    share.target = "_blank"; share.rel = "noopener";
    act.appendChild(share);
    body.appendChild(act);
    card.appendChild(body);
    return card;
  }

  function render() {
    var my = mine();
    list.innerHTML = "";
    var now = Date.now();
    var pinned = (fromDb ? posts.filter(function (p) { return p.pinned; }).map(function (p) {
      return { title: p.title, body: p.body, name: p.name, phone: p.phone, deadline: p.deadline,
               link: p.link_url ? { url: p.link_url, label: p.link_label || "Open link" } : null };
    }) : (window.TAXI_PINNED || [])).filter(function (p) {
      return (!p.expires || Date.parse(p.expires) > now) && (filter === "all" || filter === "notice");
    });
    pinned.forEach(function (p) { list.appendChild(renderPinned(p)); });
    var shown = posts.filter(function (p) { return !p.pinned; }).filter(function (p) { var t = p.type === "wanted" ? "advert" : p.type; return filter === "all" || t === filter; });
    shown.forEach(function (p) {
      var isAd = p.type !== "notice";
      var card = el("article", "post post--" + (isAd ? "advert" : "notice"));
      if (p.image) {
        var img = el("img", "post__img");
        img.src = p.image;
        img.alt = "";
        img.loading = "lazy";
        card.appendChild(img);
      }
      var body = el("div", "post__body");
      var meta = el("div", "post__meta");
      meta.appendChild(el("span", "tag tag--" + (isAd ? "advert" : "notice"), isAd ? (CAT[p.category] || LABEL.advert) : LABEL.notice));
      meta.appendChild(el("span", "post__time", ago(p.created)));
      body.appendChild(meta);
      body.appendChild(el("h3", "post__title", p.title));
      if (isAd && p.price) body.appendChild(el("p", "post__price", p.price));
      body.appendChild(el("p", "post__text", p.body));
      var who = el("p", "post__who");
      who.textContent = "👤 " + p.name + (p.area ? "  ·  📍 " + p.area : "");
      body.appendChild(who);

      var act = el("div", "post__actions");
      if (p.phone) {
        var call = el("a", "btn btn--sm btn--call", "📞 Call");
        call.href = "tel:+" + intl(p.phone);
        act.appendChild(call);
        var wa = el("a", "btn btn--sm btn--wa", "WhatsApp");
        wa.href = "https://wa.me/" + intl(p.phone) + "?text=" + encodeURIComponent("Hi, about your Taxi Live post: " + p.title);
        wa.target = "_blank"; wa.rel = "noopener";
        act.appendChild(wa);
      }
      var share = el("a", "btn btn--sm btn--ghost-dark", "↗ Share");
      share.href = "https://wa.me/?text=" + encodeURIComponent(
        (isAd ? "📣 " : "📰 ") + p.title + (p.price ? " – " + p.price : "") + "\n" + p.body.slice(0, 200) +
        (p.phone ? "\n📞 " + p.phone : "") + "\n\nSeen on " + location.origin + "/#board");
      share.target = "_blank"; share.rel = "noopener";
      act.appendChild(share);

      if (my[p.id] && !fromDb) {
        var del = el("button", "linkbtn linkbtn--danger", "Delete my post");
        del.type = "button";
        del.addEventListener("click", function () {
          if (!confirm("Delete this post?")) return;
          var fd = new FormData();
          fd.append("action", "delete"); fd.append("id", p.id); fd.append("token", my[p.id]);
          post(fd).then(function () {
            var m = mine(); delete m[p.id]; setMine(m); toast("Post deleted"); load();
          }).catch(function (e) { toast(e.message); });
        });
        act.appendChild(del);
      } else {
        var rep = el("button", "linkbtn", "Report");
        rep.type = "button";
        rep.addEventListener("click", function () {
          if (!confirm("Report this post as spam or inappropriate?")) return;
          var fd = new FormData();
          fd.append("action", "report"); fd.append("id", p.id);
          post(fd).then(function () { toast("Thanks – post reported"); rep.disabled = true; rep.textContent = "Reported"; })
            .catch(function (e) { toast(e.message); });
        });
        act.appendChild(rep);
      }
      body.appendChild(act);
      card.appendChild(body);
      list.appendChild(card);
    });
    emptyBox.hidden = shown.length + pinned.length !== 0;
  }

  chips.addEventListener("click", function (e) {
    var b = e.target.closest(".chip");
    if (!b) return;
    filter = b.dataset.type;
    Array.prototype.forEach.call(chips.children, function (c) {
      c.setAttribute("aria-selected", c === b ? "true" : "false");
    });
    render();
  });

  load();
})();
