/* Members: sign in with an email code, then edit or remove their own posts. */
(function () {
  "use strict";
  var API = "api/member.php";
  var CATS = [["rent", "🚕 For rent"], ["sale", "🏷️ For sale"], ["wanted", "🔎 Wanted"], ["service", "🔧 Service"], ["other", "➕ Other"]];
  var $ = function (id) { return document.getElementById(id); };
  var err = $("err"), stepEmail = $("stepEmail"), stepCode = $("stepCode"), stepPosts = $("stepPosts");
  var email = "", csrf = "";

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }
  function toast(msg) {
    var t = $("toast"); t.textContent = msg; t.classList.add("is-on");
    clearTimeout(toast.t); toast.t = setTimeout(function () { t.classList.remove("is-on"); }, 2600);
  }
  function fail(msg) { err.textContent = msg; err.hidden = false; err.scrollIntoView({ block: "center", behavior: "smooth" }); }
  function call(method, action, data) {
    var opt = { method: method, credentials: "same-origin", headers: {} };
    var url = API + "?action=" + action;
    if (method === "POST") {
      var fd = data instanceof FormData ? data : new FormData();
      if (!(data instanceof FormData)) for (var k in data || {}) fd.append(k, data[k]);
      fd.append("action", action);
      opt.body = fd;
      if (csrf) opt.headers["X-CSRF-Token"] = csrf;
      url = API;
    }
    return fetch(url, opt).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) { var e = new Error(j.error || "Something went wrong. Please try again."); e.status = r.status; throw e; }
        return j;
      });
    });
  }
  function step(which) {
    err.hidden = true;
    stepEmail.hidden = which !== "email";
    stepCode.hidden = which !== "code";
    stepPosts.hidden = which !== "posts";
    $("lead").hidden = which === "posts";
  }
  function busy(btn, on, label) { btn.disabled = on; if (label) btn.textContent = label; }

  // Step 1
  function sendCode(btn) {
    busy(btn, true, "Sending…");
    return call("POST", "send_code", { email: email }).then(function () {
      $("sentTo").textContent = "If " + email + " has posts on Taxi Live, a code is on its way.";
      step("code");
      $("m-code").value = "";
      $("m-code").focus();
    }).catch(function (e) { fail(e.message); }).then(function () { busy(btn, false, btn === $("resend") ? "Send a new code" : "Email me a code"); });
  }
  stepEmail.addEventListener("submit", function (e) {
    e.preventDefault();
    email = $("m-email").value.trim().toLowerCase();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return fail("Please enter a valid email address.");
    sendCode(stepEmail.querySelector("button[type=submit]"));
  });
  $("resend").addEventListener("click", function () { sendCode($("resend")).then(function () { toast("New code sent"); }); });
  $("changeEmail").addEventListener("click", function () { step("email"); $("m-email").focus(); });

  // Step 2
  stepCode.addEventListener("submit", function (e) {
    e.preventDefault();
    var code = $("m-code").value.replace(/\D/g, "");
    if (code.length !== 6) return fail("Enter the 6-digit code from the email.");
    var btn = stepCode.querySelector("button[type=submit]");
    busy(btn, true, "Checking…");
    call("POST", "verify", { email: email, code: code }).then(function (j) {
      csrf = j.csrf; email = j.email; showPosts();
    }).catch(function (e) { fail(e.message); }).then(function () { busy(btn, false, "Show my posts"); });
  });
  $("m-code").addEventListener("input", function () {
    this.value = this.value.replace(/\D/g, "").slice(0, 6);
    if (this.value.length === 6) stepCode.requestSubmit ? stepCode.requestSubmit() : stepCode.dispatchEvent(new Event("submit"));
  });

  // Step 3
  $("logout").addEventListener("click", function () {
    call("POST", "logout").then(function () { csrf = ""; step("email"); toast("Signed out"); });
  });

  function showPosts() {
    $("who").textContent = email;
    step("posts");
    var box = $("myPosts");
    box.innerHTML = "<p class='muted'>Loading your posts…</p>";
    call("GET", "mine").then(function (j) {
      box.innerHTML = "";
      if (!j.posts.length) { box.appendChild(el("p", "board-empty", "You have no posts at the moment.")); return; }
      j.posts.forEach(function (p) { box.appendChild(card(p)); });
    }).catch(function (e) {
      if (e.status === 401) step("email"); else fail(e.message);
    });
  }

  function statusBadge(p) {
    if (p.status === "hidden") return el("span", "mstatus mstatus--hidden", "Hidden by admin");
    if (p.expired) return el("span", "mstatus mstatus--expired", "Expired – edit to renew");
    return el("span", "mstatus mstatus--live", "● Live");
  }

  function card(p) {
    var c = el("article", "mpost");
    var top = el("div", "mpost__top");
    if (p.image) { var img = el("img", "mpost__img"); img.src = p.image; img.alt = ""; top.appendChild(img); }
    var info = el("div", "mpost__info");
    var meta = el("div", "mpost__meta");
    meta.appendChild(statusBadge(p));
    meta.appendChild(el("span", "", (p.type === "notice" ? "Notice" : "Advert") + " · " + new Date(p.created * 1000).toLocaleDateString("en-IE", { day: "numeric", month: "short" })));
    info.appendChild(meta);
    info.appendChild(el("h3", "", p.title));
    if (p.price) info.appendChild(el("p", "post__price", p.price));
    top.appendChild(info);
    c.appendChild(top);

    var actions = el("div", "mpost__actions");
    var edit = el("button", "btn btn--sm btn--dark", "✏️ Edit");
    var del = el("button", "btn btn--sm btn--danger", "🗑 Remove");
    edit.type = del.type = "button";
    actions.appendChild(edit); actions.appendChild(del);
    c.appendChild(actions);

    del.addEventListener("click", function () {
      if (!confirm("Remove “" + p.title + "” from the noticeboard? This can't be undone.")) return;
      call("POST", "delete", { id: p.id }).then(function () { toast("Post removed"); showPosts(); }).catch(function (e) { fail(e.message); });
    });
    edit.addEventListener("click", function () {
      var open = c.querySelector(".mform");
      if (open) { open.remove(); edit.textContent = "✏️ Edit"; return; }
      c.appendChild(form(p, function () { toast("✅ Saved – your post is live for another 30 days"); showPosts(); }));
      edit.textContent = "Close";
    });
    return c;
  }

  function field(label, input, hint) {
    var g = el("div", "fgroup");
    var l = el("label", "flabel", label);
    g.appendChild(l); g.appendChild(input);
    if (hint) g.appendChild(el("p", "fhint", hint));
    return g;
  }
  function input(name, value, attrs) {
    var i = el(attrs && attrs.textarea ? "textarea" : "input", "finput");
    i.name = name; i.value = value || "";
    for (var k in attrs || {}) if (k !== "textarea") i.setAttribute(k, attrs[k]);
    return i;
  }

  function form(p, done) {
    var f = el("form", "mform");
    f.noValidate = true;
    var type = el("select", "finput"); type.name = "type";
    [["advert", "📣 Advert"], ["notice", "📰 Notice"]].forEach(function (o) { var op = el("option", "", o[1]); op.value = o[0]; type.appendChild(op); });
    type.value = p.type === "notice" ? "notice" : "advert";
    var cat = el("select", "finput"); cat.name = "category";
    CATS.forEach(function (o) { var op = el("option", "", o[1]); op.value = o[0]; cat.appendChild(op); });
    cat.value = p.category || "other";
    var catG = field("Category", cat);
    var price = input("price", p.price, { maxlength: 30, placeholder: "e.g. €250 / week" });
    var priceG = field("Price (optional)", price);
    function sync() { catG.hidden = priceG.hidden = type.value !== "advert"; }
    type.addEventListener("change", sync);

    f.appendChild(field("Type", type));
    f.appendChild(catG);
    f.appendChild(field("Title", input("title", p.title, { maxlength: 80, required: "" })));
    f.appendChild(priceG);
    f.appendChild(field("Details", input("body", p.body, { textarea: true, rows: 5, maxlength: 1000, required: "" })));
    var row = el("div", "frow");
    row.appendChild(field("Your name", input("name", p.name, { maxlength: 40, required: "" })));
    row.appendChild(field("Area (optional)", input("area", p.area, { maxlength: 40 })));
    f.appendChild(row);
    f.appendChild(field("Phone / WhatsApp (optional)", input("phone", p.phone, { type: "tel", maxlength: 20 })));
    var file = input("image", "", { type: "file", accept: "image/*" });
    file.value = "";
    f.appendChild(field(p.image ? "Replace photo (optional)" : "Add a photo (optional)", file));
    if (p.image) {
      var rm = el("label", "checkline");
      var cb = el("input"); cb.type = "checkbox"; cb.name = "remove_image"; cb.value = "1";
      rm.appendChild(cb); rm.appendChild(document.createTextNode(" Remove the current photo"));
      f.appendChild(rm);
    }
    var save = el("button", "btn btn--primary btn--block", "Save changes");
    save.type = "submit";
    f.appendChild(save);
    sync();

    f.addEventListener("submit", function (e) {
      e.preventDefault();
      err.hidden = true;
      var fd = new FormData(f);
      fd.set("id", p.id);
      if (type.value !== "advert") { fd.delete("category"); fd.delete("price"); }
      if (!file.files.length) fd.delete("image");
      busy(save, true, "Saving…");
      shrink(file.files[0]).then(function (img) {
        if (img) fd.set("image", img, "photo.jpg");
        return call("POST", "update", fd);
      }).then(done).catch(function (x) { fail(x.message); }).then(function () { busy(save, false, "Save changes"); });
    });
    return f;
  }

  // Resize big phone photos before upload.
  function shrink(file) {
    return new Promise(function (resolve) {
      if (!file) return resolve(null);
      var url = URL.createObjectURL(file), img = new Image();
      img.onload = function () {
        var k = Math.min(1, 1600 / Math.max(img.naturalWidth, img.naturalHeight));
        var cv = document.createElement("canvas");
        cv.width = Math.round(img.naturalWidth * k); cv.height = Math.round(img.naturalHeight * k);
        cv.getContext("2d").drawImage(img, 0, 0, cv.width, cv.height);
        URL.revokeObjectURL(url);
        cv.toBlob(function (b) { resolve(b || file); }, "image/jpeg", 0.82);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }

  // Already signed in this session?
  call("GET", "me").then(function (j) { csrf = j.csrf; email = j.email; showPosts(); })
    .catch(function (e) {
      step("email");
      if (e.status === 503) fail("Editing posts will be available shortly. Please check back soon.");
    });
})();
