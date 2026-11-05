(function () {
  "use strict";
  var groups = window.TAXI_CONTACTS || [];
  var grid = document.getElementById("contacts");
  var chips = document.getElementById("chips");
  var q = document.getElementById("q");
  var empty = document.getElementById("empty");
  var toast = document.getElementById("toast");
  var active = "all";

  // "01 413 5960" -> "+35314135960"
  function tel(num) {
    var d = num.replace(/\D/g, "");
    return d.charAt(0) === "0" ? "+353" + d.slice(1) : "+" + d;
  }

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }

  function renderChips() {
    var all = [{ id: "all", title: "All", icon: "⭐" }].concat(groups);
    all.forEach(function (g) {
      var b = el("button", "chip", g.icon + " " + g.title);
      b.type = "button";
      b.setAttribute("role", "tab");
      b.dataset.id = g.id;
      b.setAttribute("aria-selected", g.id === active ? "true" : "false");
      b.addEventListener("click", function () {
        active = g.id;
        Array.prototype.forEach.call(chips.children, function (c) {
          c.setAttribute("aria-selected", c.dataset.id === active ? "true" : "false");
        });
        filter();
      });
      chips.appendChild(b);
    });
  }

  function renderGroups() {
    grid.innerHTML = "";
    groups.forEach(function (g) {
      var box = el("section", "group");
      box.dataset.id = g.id;
      var h = el("h3", "group__title");
      h.appendChild(el("span", "group__icon", g.icon));
      h.appendChild(document.createTextNode(g.title));
      box.appendChild(h);
      var ul = el("ul", "list");
      g.items.forEach(function (it) {
        var li = el("li", "row");
        li.dataset.search = (g.title + " " + it.join(" ")).toLowerCase();
        var a = el("a", "row__main");
        a.href = "tel:" + tel(it[1]);
        var info = el("span", "row__info");
        info.appendChild(el("span", "row__name", it[0]));
        if (it[2]) info.appendChild(el("span", "row__note", it[2]));
        a.appendChild(info);
        a.appendChild(el("span", "row__num", it[1]));
        li.appendChild(a);
        if (it[2] && /\.(ie|com)$/.test(it[2])) {
          var w = el("a", "row__btn", "↗");
          w.href = "https://www." + it[2];
          w.target = "_blank";
          w.rel = "noopener";
          w.setAttribute("aria-label", "Open " + it[2]);
          li.appendChild(w);
        }
        var c = el("button", "row__btn", "⧉");
        c.type = "button";
        c.setAttribute("aria-label", "Copy " + it[0] + " number");
        c.addEventListener("click", function () { copy(it[1], it[0]); });
        li.appendChild(c);
        ul.appendChild(li);
      });
      box.appendChild(ul);
      grid.appendChild(box);
    });
  }

  function filter() {
    var term = (q.value || "").trim().toLowerCase();
    var shown = 0;
    Array.prototype.forEach.call(grid.children, function (box) {
      var inCat = active === "all" || box.dataset.id === active;
      var any = 0;
      Array.prototype.forEach.call(box.querySelectorAll(".row"), function (r) {
        var ok = inCat && (!term || r.dataset.search.indexOf(term) !== -1);
        r.hidden = !ok;
        if (ok) any++;
      });
      box.hidden = !any;
      shown += any;
    });
    empty.hidden = shown !== 0;
  }

  function copy(num, name) {
    var done = function () { show("Copied " + name + ": " + num); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(num).then(done, function () { show(num); });
    } else {
      show(num);
    }
  }

  var t;
  function show(msg) {
    toast.textContent = msg;
    toast.classList.add("is-on");
    clearTimeout(t);
    t = setTimeout(function () { toast.classList.remove("is-on"); }, 2200);
  }

  renderChips();
  renderGroups();
  q.addEventListener("input", filter);
  document.getElementById("year").textContent = new Date().getFullYear();
})();
