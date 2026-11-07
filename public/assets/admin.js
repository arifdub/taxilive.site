/* Taxi Live admin panel. */
(function () {
  "use strict";
  var API = "../api/admin.php";
  var CATS = [["rent", "🚕 For rent"], ["sale", "🏷️ For sale"], ["wanted", "🔎 Wanted"], ["service", "🔧 Service"], ["other", "➕ Other"]];
  var CAT_LABEL = {}; CATS.forEach(function (c) { CAT_LABEL[c[0]] = c[1]; });
  var $ = function (id) { return document.getElementById(id); };
  var csrf = "";
  var state = { status: "all", type: "", q: "" };

  /* ---------- helpers ---------- */
  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }
  function toast(msg, bad) {
    var t = $("toast"); t.textContent = msg; t.classList.toggle("toast--bad", !!bad); t.classList.add("is-on");
    clearTimeout(toast.t); toast.t = setTimeout(function () { t.classList.remove("is-on"); }, 2800);
  }
  function api(method, action, data) {
    var url = API + "?action=" + encodeURIComponent(action);
    var opt = { method: method, credentials: "same-origin", headers: {} };
    if (method === "GET" && data) for (var k in data) url += "&" + encodeURIComponent(k) + "=" + encodeURIComponent(data[k]);
    if (method === "POST") {
      var fd = data instanceof FormData ? data : new FormData();
      if (!(data instanceof FormData)) for (var key in data || {}) fd.append(key, data[key] == null ? "" : data[key]);
      fd.set("action", action);
      opt.body = fd;
      opt.headers["X-CSRF-Token"] = csrf;
    }
    return fetch(url, opt).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (r.status === 401 && action !== "login") { showLogin(); }
        if (!r.ok) { var e = new Error(j.error || "Something went wrong"); e.status = r.status; throw e; }
        return j;
      });
    });
  }
  function fmtDate(ts) {
    return ts ? new Date(ts * 1000).toLocaleString("en-IE", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "—";
  }
  function isoDate(ts) {
    if (!ts) return "";
    var d = new Date(ts * 1000);
    return d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0") + "-" + String(d.getDate()).padStart(2, "0");
  }
  function field(label, input, hint) {
    var g = el("div", "fgroup");
    g.appendChild(el("label", "flabel", label));
    g.appendChild(input);
    if (hint) g.appendChild(el("p", "fhint", hint));
    return g;
  }
  function inp(name, value, attrs) {
    var i = el(attrs && attrs.textarea ? "textarea" : "input", "finput");
    i.name = name; i.value = value == null ? "" : value;
    for (var k in attrs || {}) if (k !== "textarea") i.setAttribute(k, attrs[k]);
    return i;
  }
  function select(name, options, value) {
    var s = el("select", "finput"); s.name = name;
    options.forEach(function (o) { var op = el("option", "", o[1]); op.value = o[0]; s.appendChild(op); });
    s.value = value;
    return s;
  }
  function btn(label, cls, onClick) {
    var b = el("button", "btn btn--sm " + (cls || "btn--ghost-dark"), label);
    b.type = "button";
    if (onClick) b.addEventListener("click", onClick);
    return b;
  }

  /* ---------- login / session ---------- */
  function showLogin() {
    $("app").hidden = true; $("adminUser").hidden = true; $("setupMsg").hidden = true;
    $("loginForm").hidden = false; $("l-user").focus();
  }
  function showApp(user) {
    $("loginForm").hidden = true; $("setupMsg").hidden = true;
    $("app").hidden = false; $("adminUser").hidden = false;
    $("adminName").textContent = "👤 " + user;
    loadPosts();
  }
  $("loginForm").addEventListener("submit", function (e) {
    e.preventDefault();
    var b = this.querySelector("button[type=submit]");
    $("loginErr").hidden = true; b.disabled = true;
    api("POST", "login", { username: $("l-user").value.trim(), password: $("l-pass").value })
      .then(function (j) { csrf = j.csrf; $("l-pass").value = ""; showApp(j.user); })
      .catch(function (x) { $("loginErr").textContent = x.message; $("loginErr").hidden = false; })
      .then(function () { b.disabled = false; });
  });
  $("logoutBtn").addEventListener("click", function () { api("POST", "logout").then(showLogin); });

  /* ---------- tabs ---------- */
  document.querySelector(".admin-tabs").addEventListener("click", function (e) {
    var b = e.target.closest("[data-tab]"); if (!b) return;
    document.querySelectorAll(".admin-tabs [data-tab]").forEach(function (t) { t.setAttribute("aria-selected", t === b ? "true" : "false"); });
    document.querySelectorAll("[data-panel]").forEach(function (p) { p.hidden = p.dataset.panel !== b.dataset.tab; });
    if (b.dataset.tab === "posts") loadPosts();
    if (b.dataset.tab === "numbers") loadNumbers();
    if (b.dataset.tab === "admins") loadAdmins();
  });

  /* ---------- posts ---------- */
  $("statusChips").addEventListener("click", function (e) {
    var c = e.target.closest(".chip"); if (!c) return;
    state.status = c.dataset.status;
    this.querySelectorAll(".chip").forEach(function (x) { x.setAttribute("aria-selected", x === c ? "true" : "false"); });
    loadPosts();
  });
  var searchT;
  $("postSearch").addEventListener("input", function () { clearTimeout(searchT); state.q = this.value; searchT = setTimeout(loadPosts, 300); });
  $("postType").addEventListener("change", function () { state.type = this.value; loadPosts(); });
  $("newPost").addEventListener("click", function () { openEditor(null); });

  function loadPosts() {
    api("GET", "posts_list", state).then(function (j) {
      var list = $("postList"); list.innerHTML = "";
      $("postCount").textContent = j.posts.length + (j.posts.length === 1 ? " post" : " posts");
      j.posts.forEach(function (p) { list.appendChild(postCard(p)); });
      if (!j.posts.length) list.appendChild(el("p", "board-empty", "No posts here."));
    }).catch(function (x) { if (x.status !== 401) toast(x.message, true); });
  }

  function postCard(p) {
    var now = Date.now() / 1000;
    var expired = p.expires && p.expires < now;
    var c = el("article", "apost" + (p.status === "hidden" ? " apost--hidden" : ""));
    if (p.image) { var img = el("img", "apost__img"); img.src = "../" + p.image; img.alt = ""; img.loading = "lazy"; c.appendChild(img); }
    var b = el("div", "apost__body");
    var badges = el("div", "apost__badges");
    badges.appendChild(el("span", "tag tag--" + p.type, p.type === "notice" ? "📰 Notice" : (CAT_LABEL[p.category] || "📣 Advert")));
    if (p.pinned) badges.appendChild(el("span", "tag tag--pinned", "📌 Pinned"));
    if (p.status === "hidden") badges.appendChild(el("span", "tag tag--hidden", "Hidden"));
    else if (expired) badges.appendChild(el("span", "tag tag--hidden", "Expired"));
    else badges.appendChild(el("span", "tag tag--live", "● Live"));
    if (p.reports) badges.appendChild(el("span", "tag tag--report", "⚑ " + p.reports + " report" + (p.reports > 1 ? "s" : "")));
    b.appendChild(badges);
    b.appendChild(el("h3", "apost__title", p.title));
    if (p.price) b.appendChild(el("p", "post__price", p.price));
    var body = el("p", "apost__text", p.body);
    b.appendChild(body);
    var meta = el("p", "apost__meta");
    meta.textContent = "👤 " + p.name + (p.email ? " · ✉️ " + p.email : "") + (p.phone ? " · 📞 " + p.phone : "") + (p.area ? " · 📍 " + p.area : "") +
      "\nPosted " + fmtDate(p.created) + " · " + (p.expires ? (expired ? "Expired " : "Expires ") + fmtDate(p.expires) : "Never expires");
    b.appendChild(meta);

    var a = el("div", "apost__actions");
    a.appendChild(btn("✏️ Edit", "btn--dark", function () { openEditor(p); }));
    a.appendChild(btn(p.status === "hidden" ? "👁 Show" : "🙈 Hide", "", function () {
      api("POST", "post_status", { id: p.dbid, status: p.status === "hidden" ? "active" : "hidden" }).then(function () { toast("Updated"); loadPosts(); });
    }));
    a.appendChild(btn(p.pinned ? "Unpin" : "📌 Pin", "", function () {
      api("POST", "post_pin", { id: p.dbid, pinned: p.pinned ? "" : "1" }).then(function () { toast(p.pinned ? "Unpinned" : "Pinned to top"); loadPosts(); });
    }));
    if (expired) a.appendChild(btn("↻ Renew 30 days", "", function () {
      api("POST", "post_renew", { id: p.dbid }).then(function () { toast("Renewed"); loadPosts(); });
    }));
    a.appendChild(btn("🗑 Delete", "btn--danger", function () {
      if (!confirm("Delete “" + p.title + "” permanently?")) return;
      api("POST", "post_delete", { id: p.dbid }).then(function () { toast("Deleted"); loadPosts(); });
    }));
    b.appendChild(a);
    c.appendChild(b);
    return c;
  }

  function openEditor(p) {
    var box = $("postEditor"); box.innerHTML = "";
    p = p || { type: "notice", category: "other", title: "", body: "", price: "", name: "Taxi Live", email: "", phone: "", area: "",
               link_url: "", link_label: "", deadline: "", pinned: false, status: "active", expires: null };
    var f = el("form", "formcard aeditor"); f.noValidate = true;
    f.appendChild(el("h2", "", p.dbid ? "Edit post" : "New post"));
    var type = select("type", [["advert", "📣 Advert"], ["notice", "📰 Notice"]], p.type);
    var cat = select("category", CATS, p.category || "other");
    var catG = field("Category", cat), priceG = field("Price", inp("price", p.price, { maxlength: 30 }));
    function sync() { catG.hidden = priceG.hidden = type.value !== "advert"; }
    type.addEventListener("change", sync);
    var r1 = el("div", "frow"); r1.appendChild(field("Type", type)); r1.appendChild(catG); f.appendChild(r1);
    f.appendChild(field("Title", inp("title", p.title, { maxlength: 160 })));
    f.appendChild(priceG);
    f.appendChild(field("Details", inp("body", p.body, { textarea: true, rows: 7, maxlength: 5000 })));
    var r2 = el("div", "frow"); r2.appendChild(field("Name", inp("name", p.name, { maxlength: 60 }))); r2.appendChild(field("Phone", inp("phone", p.phone, { maxlength: 20 }))); f.appendChild(r2);
    var r3 = el("div", "frow"); r3.appendChild(field("Email (private)", inp("email", p.email, { type: "email" }), "The member uses this to edit their post.")); r3.appendChild(field("Area", inp("area", p.area, { maxlength: 60 }))); f.appendChild(r3);
    f.appendChild(el("div", "fsection", "Notice extras (optional)"));
    var r4 = el("div", "frow"); r4.appendChild(field("Button link", inp("link_url", p.link_url, { placeholder: "https://…" }))); r4.appendChild(field("Button text", inp("link_label", p.link_label, { maxlength: 60, placeholder: "e.g. Respond on the NTA portal" }))); f.appendChild(r4);
    f.appendChild(field("Deadline text", inp("deadline_text", p.deadline, { maxlength: 80, placeholder: "e.g. Deadline: 18 December 2026 at 5pm" })));
    var r5 = el("div", "frow");
    r5.appendChild(field("Expires on", inp("expires", isoDate(p.expires), { type: "date" }), "Leave empty to never expire."));
    r5.appendChild(field("Status", select("status", [["active", "● Live"], ["hidden", "Hidden"]], p.status)));
    f.appendChild(r5);
    var pin = el("label", "checkline"); var cb = el("input"); cb.type = "checkbox"; cb.name = "pinned"; cb.value = "1"; cb.checked = !!p.pinned;
    pin.appendChild(cb); pin.appendChild(document.createTextNode(" 📌 Pin to the top of the noticeboard")); f.appendChild(pin);
    var file = inp("image", "", { type: "file", accept: "image/*" }); file.value = "";
    f.appendChild(field(p.image ? "Replace photo" : "Photo (optional)", file));
    if (p.image) { var rm = el("label", "checkline"); var rcb = el("input"); rcb.type = "checkbox"; rcb.name = "remove_image"; rcb.value = "1"; rm.appendChild(rcb); rm.appendChild(document.createTextNode(" Remove current photo")); f.appendChild(rm); }
    var row = el("div", "aeditor__btns");
    var save = el("button", "btn btn--primary", p.dbid ? "Save changes" : "Publish"); save.type = "submit";
    row.appendChild(save); row.appendChild(btn("Cancel", "", function () { box.innerHTML = ""; }));
    f.appendChild(row);
    sync();
    f.addEventListener("submit", function (e) {
      e.preventDefault();
      var fd = new FormData(f);
      if (p.dbid) fd.set("id", p.dbid);
      if (!file.files.length) fd.delete("image");
      save.disabled = true;
      api("POST", "post_save", fd).then(function () { toast("Saved"); box.innerHTML = ""; loadPosts(); })
        .catch(function (x) { toast(x.message, true); }).then(function () { save.disabled = false; });
    });
    box.appendChild(f);
    f.scrollIntoView({ behavior: "smooth", block: "start" });
  }

  /* ---------- numbers ---------- */
  $("newCat").addEventListener("click", function () {
    var t = prompt("New category name (e.g. Garda Stations):"); if (!t) return;
    var icon = prompt("Icon / emoji for “" + t + "” (optional):", "📞") || "";
    api("POST", "cat_save", { title: t, icon: icon }).then(function () { toast("Category added"); loadNumbers(); }).catch(function (x) { toast(x.message, true); });
  });

  function loadNumbers() {
    api("GET", "numbers_list").then(function (j) {
      var box = $("numList"); box.innerHTML = "";
      j.categories.forEach(function (c) { box.appendChild(catPanel(c, j.categories)); });
    }).catch(function (x) { if (x.status !== 401) toast(x.message, true); });
  }

  function catPanel(c, all) {
    var p = el("section", "formcard acat");
    var head = el("form", "acat__head"); head.noValidate = true;
    head.appendChild(inp("icon", c.icon, { maxlength: 16, "aria-label": "Icon", class: "finput acat__icon" }));
    head.appendChild(inp("title", c.title, { maxlength: 80, "aria-label": "Category name", class: "finput acat__title" }));
    head.appendChild(inp("sort_order", c.sort_order, { type: "number", "aria-label": "Order", title: "Order", class: "finput acat__sort" }));
    var s = el("button", "btn btn--sm btn--dark", "Save"); s.type = "submit"; head.appendChild(s);
    head.appendChild(btn("Delete", "btn--danger", function () {
      if (!confirm("Delete the category “" + c.title + "” and all " + c.items.length + " numbers in it?")) return;
      api("POST", "cat_delete", { id: c.id }).then(function () { toast("Category deleted"); loadNumbers(); });
    }));
    head.addEventListener("submit", function (e) {
      e.preventDefault();
      var fd = new FormData(head); fd.set("id", c.id);
      api("POST", "cat_save", fd).then(function () { toast("Category saved"); loadNumbers(); }).catch(function (x) { toast(x.message, true); });
    });
    p.appendChild(head);
    var list = el("div", "acontacts");
    list.appendChild(headerRow());
    c.items.forEach(function (it) { list.appendChild(contactRow(it, c, all)); });
    list.appendChild(contactRow({ id: 0, name: "", phone: "", note: "", url: "", sort_order: "" }, c, all));
    p.appendChild(list);
    p.appendChild(el("p", "muted acat__link", "Public page: /numbers/?c=" + c.slug));
    return p;
  }
  function headerRow() {
    var r = el("div", "arow arow--head");
    ["Name", "Phone", "Note", "Website", "Order", ""].forEach(function (h) { r.appendChild(el("span", "", h)); });
    return r;
  }
  function contactRow(it, c, all) {
    var f = el("form", "arow" + (it.id ? "" : " arow--new")); f.noValidate = true;
    f.appendChild(inp("name", it.name, { maxlength: 100, placeholder: it.id ? "" : "＋ New name", "aria-label": "Name" }));
    f.appendChild(inp("phone", it.phone, { maxlength: 30, placeholder: "01 234 5678", "aria-label": "Phone", inputmode: "tel" }));
    f.appendChild(inp("note", it.note, { maxlength: 120, placeholder: "e.g. 24 hours", "aria-label": "Note" }));
    f.appendChild(inp("url", it.url, { maxlength: 255, placeholder: "https://…", "aria-label": "Website" }));
    f.appendChild(inp("sort_order", it.sort_order, { type: "number", "aria-label": "Order" }));
    var acts = el("div", "arow__btns");
    var s = el("button", "btn btn--sm " + (it.id ? "btn--dark" : "btn--primary"), it.id ? "Save" : "Add"); s.type = "submit"; acts.appendChild(s);
    if (it.id) {
      if (all.length > 1) {
        var mv = select("category_id", all.map(function (x) { return [String(x.id), "Move: " + x.title]; }), String(c.id));
        mv.className = "finput arow__move"; mv.setAttribute("aria-label", "Category");
        acts.appendChild(mv);
      }
      acts.appendChild(btn("✕", "btn--danger", function () {
        if (!confirm("Delete " + it.name + "?")) return;
        api("POST", "contact_delete", { id: it.id }).then(function () { toast("Deleted"); loadNumbers(); });
      }));
    }
    f.appendChild(acts);
    f.addEventListener("submit", function (e) {
      e.preventDefault();
      var fd = new FormData(f);
      if (it.id) fd.set("id", it.id);
      if (!fd.get("category_id")) fd.set("category_id", c.id);
      api("POST", "contact_save", fd).then(function () { toast(it.id ? "Saved" : "Number added"); loadNumbers(); }).catch(function (x) { toast(x.message, true); });
    });
    return f;
  }

  /* ---------- admins ---------- */
  function loadAdmins() {
    api("GET", "admins_list").then(function (j) {
      var box = $("adminList"); box.innerHTML = "";
      j.admins.forEach(function (a) {
        var r = el("div", "aadmin");
        var t = el("div", ""); t.appendChild(el("b", "", a.username + (a.id === j.me ? " (you)" : "")));
        t.appendChild(el("span", "muted", a.email + " · last login " + fmtDate(a.last_login)));
        r.appendChild(t);
        if (a.id !== j.me) r.appendChild(btn("Remove", "btn--danger", function () {
          if (!confirm("Remove admin " + a.username + "?")) return;
          api("POST", "admin_delete", { id: a.id }).then(function () { toast("Removed"); loadAdmins(); });
        }));
        box.appendChild(r);
      });
    });
  }
  $("addAdmin").addEventListener("submit", function (e) {
    e.preventDefault(); var f = this;
    api("POST", "admin_add", new FormData(f)).then(function () { f.reset(); toast("Admin added"); loadAdmins(); }).catch(function (x) { toast(x.message, true); });
  });
  $("pwForm").addEventListener("submit", function (e) {
    e.preventDefault(); var f = this;
    api("POST", "password", new FormData(f)).then(function () { f.reset(); toast("Password changed"); }).catch(function (x) { toast(x.message, true); });
  });

  /* ---------- start ---------- */
  api("GET", "me").then(function (j) { csrf = j.csrf; showApp(j.user); }).catch(function (x) {
    if (x.status === 503) { $("setupMsg").hidden = false; return; }
    showLogin();
  });
})();
