(function () {
  "use strict";
  var API = "api/posts.php";
  var form = document.getElementById("postForm");
  var seg = document.querySelectorAll(".seg__btn");
  var err = document.getElementById("formError");
  var submit = document.getElementById("submitPost");
  var fileIn = document.getElementById("f-image");
  var preview = document.getElementById("preview");
  var drop = document.getElementById("drop");
  var removePhoto = document.getElementById("removePhoto");
  var body = document.getElementById("f-body");
  var count = document.getElementById("count");
  var success = document.getElementById("success");
  var type = "advert";

  var COPY = {
    advert: { btn: "Publish advert", title: "e.g. Toyota Prius for rent – Dublin 15", body: "Condition, dates, what's included…" },
    notice: { btn: "Publish notice", title: "e.g. Road closure on the quays tonight", body: "What's happening, where and when…" },
  };

  function setType(t) {
    type = t === "notice" ? "notice" : "advert";
    form.elements.type.value = type;
    seg.forEach(function (b) { b.setAttribute("aria-selected", b.dataset.type === type ? "true" : "false"); });
    document.querySelectorAll("[data-only]").forEach(function (el) { el.hidden = el.dataset.only !== type; });
    submit.textContent = COPY[type].btn;
    document.getElementById("titleHint").textContent = COPY[type].title;
    document.getElementById("bodyHint").textContent = COPY[type].body;
    err.hidden = true;
    try { history.replaceState(null, "", "?type=" + type); } catch (e) {}
  }
  seg.forEach(function (b) { b.addEventListener("click", function () { setType(b.dataset.type); }); });
  setType(new URLSearchParams(location.search).get("type"));

  body.addEventListener("input", function () { count.textContent = body.value.length + " / 1000"; });

  function clearPhoto() {
    fileIn.value = "";
    preview.hidden = true;
    preview.removeAttribute("src");
    drop.classList.remove("has-img");
    removePhoto.hidden = true;
  }
  fileIn.addEventListener("change", function () {
    var f = fileIn.files && fileIn.files[0];
    if (!f) return clearPhoto();
    preview.src = URL.createObjectURL(f);
    preview.hidden = false;
    drop.classList.add("has-img");
    removePhoto.hidden = false;
  });
  removePhoto.addEventListener("click", clearPhoto);

  // Shrink phone photos before upload (also strips location data).
  function shrink(file) {
    return new Promise(function (resolve) {
      if (!file || !file.size) return resolve(null);
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        var k = Math.min(1, 1600 / Math.max(img.naturalWidth, img.naturalHeight));
        var c = document.createElement("canvas");
        c.width = Math.round(img.naturalWidth * k);
        c.height = Math.round(img.naturalHeight * k);
        c.getContext("2d").drawImage(img, 0, 0, c.width, c.height);
        URL.revokeObjectURL(url);
        c.toBlob(function (b) { resolve(b || file); }, "image/jpeg", 0.82);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }

  function fail(msg, field) {
    err.textContent = msg;
    err.hidden = false;
    if (field) { field.focus(); field.classList.add("is-bad"); }
    err.scrollIntoView({ block: "center", behavior: "smooth" });
  }
  form.addEventListener("input", function (e) { err.hidden = true; e.target.classList && e.target.classList.remove("is-bad"); });

  form.addEventListener("submit", function (e) {
    e.preventDefault();
    err.hidden = true;
    var el = form.elements;
    if (el.title.value.trim().length < 3) return fail("Please add a title.", el.title);
    if (el.body.value.trim().length < 5) return fail("Please add some details.", el.body);
    if (!el.name.value.trim()) return fail("Please add your name.", el.name);

    var fd = new FormData(form);
    if (type !== "advert") { fd.delete("category"); fd.delete("price"); }
    submit.disabled = true;
    submit.textContent = "Publishing…";
    shrink(fileIn.files && fileIn.files[0]).then(function (img) {
      fd.delete("image");
      if (img) fd.append("image", img, "photo.jpg");
      fd.append("action", "create");
      return fetch(API, { method: "POST", body: fd });
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) throw new Error(j.error || "Couldn't publish right now. Please try again.");
        return j;
      });
    }).then(function (j) {
      if (j.id) {
        try {
          var m = JSON.parse(localStorage.getItem("tl_mine") || "{}");
          m[j.id] = j.token;
          localStorage.setItem("tl_mine", JSON.stringify(m));
        } catch (x) {}
      }
      var title = el.title.value.trim();
      document.getElementById("shareWa").href = "https://wa.me/?text=" + encodeURIComponent(
        (type === "advert" ? "📣 " : "📰 ") + title + "\n\nSee it on Taxi Live: " + location.origin + "/#board");
      form.hidden = true;
      document.querySelector(".seg").hidden = true;
      success.hidden = false;
      window.scrollTo(0, 0);
    }).catch(function (x) {
      fail(x.message || "Couldn't publish right now. Please try again.");
    }).then(function () {
      submit.disabled = false;
      submit.textContent = COPY[type].btn;
    });
  });

  document.getElementById("another").addEventListener("click", function () {
    form.reset();
    clearPhoto();
    count.textContent = "0 / 1000";
    success.hidden = true;
    form.hidden = false;
    document.querySelector(".seg").hidden = false;
    setType(type);
  });
})();
