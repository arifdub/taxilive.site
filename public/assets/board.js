(function () {
  "use strict";
  var API = "api/posts.php";
  var LABEL = { advert: "📣 Advert", notice: "📰 Notice", wanted: "🔎 Wanted" };
  var list = document.getElementById("posts");
  var emptyBox = document.getElementById("boardEmpty");
  var errBox = document.getElementById("boardError");
  var chips = document.getElementById("boardChips");
  var modal = document.getElementById("postModal");
  var form = document.getElementById("postForm");
  var formErr = document.getElementById("formError");
  var submit = document.getElementById("submitPost");
  var posts = [];
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
      .then(function (j) { posts = j.posts || []; errBox.hidden = true; render(); })
      .catch(function () { posts = []; render(); errBox.hidden = false; emptyBox.hidden = true; });
  }

  function render() {
    var my = mine();
    list.innerHTML = "";
    var shown = posts.filter(function (p) { return filter === "all" || p.type === filter; });
    shown.forEach(function (p) {
      var card = el("article", "post post--" + p.type);
      if (p.image) {
        var img = el("img", "post__img");
        img.src = p.image;
        img.alt = "";
        img.loading = "lazy";
        card.appendChild(img);
      }
      var body = el("div", "post__body");
      var meta = el("div", "post__meta");
      meta.appendChild(el("span", "tag tag--" + p.type, LABEL[p.type] || p.type));
      meta.appendChild(el("span", "post__time", ago(p.created)));
      body.appendChild(meta);
      body.appendChild(el("h3", "post__title", p.title));
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
        (LABEL[p.type] || "") + ": " + p.title + "\n" + p.body.slice(0, 200) +
        (p.phone ? "\n📞 " + p.phone : "") + "\n\nSeen on " + location.origin + "/#board");
      share.target = "_blank"; share.rel = "noopener";
      act.appendChild(share);

      if (my[p.id]) {
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
    emptyBox.hidden = shown.length !== 0;
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

  // Shrink photos in the browser (phones take huge photos) and strip metadata.
  function shrink(file) {
    return new Promise(function (resolve) {
      if (!file || !file.size || !/^image\//.test(file.type)) return resolve(file && file.size ? file : null);
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        var max = 1600, w = img.naturalWidth, h = img.naturalHeight;
        var k = Math.min(1, max / Math.max(w, h));
        var c = document.createElement("canvas");
        c.width = Math.round(w * k); c.height = Math.round(h * k);
        c.getContext("2d").drawImage(img, 0, 0, c.width, c.height);
        URL.revokeObjectURL(url);
        c.toBlob(function (b) { resolve(b || file); }, "image/jpeg", 0.82);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }

  function open() {
    formErr.hidden = true;
    if (modal.showModal) modal.showModal(); else modal.setAttribute("open", "");
  }
  function close() {
    if (modal.close) modal.close(); else modal.removeAttribute("open");
  }
  document.getElementById("openPost").addEventListener("click", open);
  document.getElementById("closePost").addEventListener("click", close);
  modal.addEventListener("click", function (e) { if (e.target === modal) close(); });

  form.addEventListener("submit", function (e) {
    e.preventDefault();
    formErr.hidden = true;
    var fd = new FormData(form);
    var need = [["title", "Please add a title."], ["body", "Please add some details."], ["name", "Please add your name."]];
    for (var i = 0; i < need.length; i++) {
      if (String(fd.get(need[i][0]) || "").trim().length < (need[i][0] === "name" ? 1 : 3)) {
        formErr.textContent = need[i][1]; formErr.hidden = false;
        form.elements[need[i][0]].focus();
        return;
      }
    }
    submit.disabled = true;
    submit.textContent = "Publishing…";
    shrink(fd.get("image")).then(function (img) {
      fd.delete("image");
      if (img) fd.append("image", img, "photo.jpg");
      fd.append("action", "create");
      return post(fd);
    }).then(function (j) {
      if (j.id) { var m = mine(); m[j.id] = j.token; setMine(m); }
      form.reset();
      close();
      toast("✅ Your post is live!");
      filter = "all";
      Array.prototype.forEach.call(chips.children, function (c) {
        c.setAttribute("aria-selected", c.dataset.type === "all" ? "true" : "false");
      });
      load();
      document.getElementById("board").scrollIntoView();
    }).catch(function (err) {
      formErr.textContent = err.message;
      formErr.hidden = false;
    }).then(function () {
      submit.disabled = false;
      submit.textContent = "Publish post";
    });
  });

  load();
})();
