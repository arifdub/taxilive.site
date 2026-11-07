/* Important numbers: category tiles (numbers/) and one page per category (numbers/?c=slug). */
(function () {
  "use strict";
  var view = document.getElementById("view");
  var q = document.getElementById("q");
  var crumbs = document.getElementById("crumbs");
  var titleEl = document.getElementById("pageTitle");
  var leadEl = document.getElementById("pageLead");
  var toast = document.getElementById("toast");
  var slug = new URLSearchParams(location.search).get("c") || "";
  var cats = [];

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }
  function tel(num) {
    var d = num.replace(/\D/g, "");
    return d.charAt(0) === "0" ? "+353" + d.slice(1) : "+" + d;
  }
  function show(msg) {
    toast.textContent = msg; toast.classList.add("is-on");
    clearTimeout(show.t); show.t = setTimeout(function () { toast.classList.remove("is-on"); }, 2200);
  }
  function copy(num, name) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(num).then(function () { show("Copied " + name + ": " + num); }, function () { show(num); });
    } else show(num);
  }

  // Static fallback (assets/contacts.js) when the database isn't set up yet.
  function fromStatic() {
    var slugs = { nct: "ncts-nta", carriage: "carriage-office", apps: "taxi-apps", insurance: "insurance", rentals: "rentals-mechanics", meters: "meter-installers" };
    return (window.TAXI_CONTACTS || []).map(function (g) {
      return {
        id: slugs[g.id] || g.id, title: g.title, icon: g.icon,
        items: g.items.map(function (it) {
          var isWeb = it[2] && /\.(ie|com)$/.test(it[2]);
          return { name: it[0], phone: it[1], note: it[2] || "", url: isWeb ? "https://www." + it[2] : "" };
        })
      };
    });
  }

  function row(it, catTitle) {
    var li = el("li", "row");
    var a = el("a", "row__main");
    a.href = "tel:" + tel(it.phone);
    var info = el("span", "row__info");
    info.appendChild(el("span", "row__name", it.name));
    var note = [catTitle, it.note].filter(Boolean).join(" · ");
    if (note) info.appendChild(el("span", "row__note", note));
    a.appendChild(info);
    a.appendChild(el("span", "row__num", it.phone));
    li.appendChild(a);
    if (it.url) {
      var w = el("a", "row__btn", "↗");
      w.href = it.url; w.target = "_blank"; w.rel = "noopener";
      w.setAttribute("aria-label", "Open website for " + it.name);
      li.appendChild(w);
    }
    var c = el("button", "row__btn", "⧉");
    c.type = "button";
    c.setAttribute("aria-label", "Copy " + it.name + " number");
    c.addEventListener("click", function () { copy(it.phone, it.name); });
    li.appendChild(c);
    return li;
  }

  function matches(it, cat, term) {
    return (cat.title + " " + it.name + " " + it.phone + " " + it.note).toLowerCase().indexOf(term) !== -1;
  }

  function setCrumbs(cat) {
    crumbs.innerHTML = "";
    var home = el("a", "", "Home"); home.href = "../"; crumbs.appendChild(home);
    crumbs.appendChild(el("span", "", "›"));
    if (cat) {
      var all = el("a", "", "Important numbers"); all.href = "./"; crumbs.appendChild(all);
      crumbs.appendChild(el("span", "", "›"));
      crumbs.appendChild(el("b", "", cat.title));
    } else {
      crumbs.appendChild(el("b", "", "Important numbers"));
    }
  }

  function renderHome() {
    var term = q.value.trim().toLowerCase();
    view.innerHTML = "";
    if (term) {
      var list = el("ul", "list list--card");
      cats.forEach(function (c) {
        c.items.forEach(function (it) { if (matches(it, c, term)) list.appendChild(row(it, c.title)); });
      });
      if (list.children.length) view.appendChild(list);
      else view.appendChild(el("p", "empty", "No numbers match “" + q.value.trim() + "”."));
      return;
    }
    var grid = el("div", "cat-tiles");
    cats.forEach(function (c) {
      var a = el("a", "cat-tile");
      a.href = "?c=" + encodeURIComponent(c.id);
      a.appendChild(el("span", "cat-tile__icon", c.icon || "📞"));
      var t = el("span", "cat-tile__text");
      t.appendChild(el("b", "", c.title));
      t.appendChild(el("span", "", c.items.length + (c.items.length === 1 ? " number" : " numbers")));
      a.appendChild(t);
      a.appendChild(el("span", "cat-tile__go", "›"));
      grid.appendChild(a);
    });
    view.appendChild(grid);
  }

  function renderCategory(cat) {
    var term = q.value.trim().toLowerCase();
    view.innerHTML = "";
    var list = el("ul", "list list--card");
    cat.items.forEach(function (it) { if (!term || matches(it, cat, term)) list.appendChild(row(it, "")); });
    if (list.children.length) view.appendChild(list);
    else view.appendChild(el("p", "empty", "No numbers match “" + q.value.trim() + "”."));

    var others = cats.filter(function (c) { return c.id !== cat.id; });
    if (others.length) {
      view.appendChild(el("h2", "subhead", "Other categories"));
      var chips = el("div", "chips chips--wrap");
      others.forEach(function (c) {
        var a = el("a", "chip", (c.icon ? c.icon + " " : "") + c.title);
        a.href = "?c=" + encodeURIComponent(c.id);
        chips.appendChild(a);
      });
      view.appendChild(chips);
    }
  }

  function render() {
    var cat = cats.filter(function (c) { return c.id === slug; })[0];
    setCrumbs(cat);
    if (cat) {
      document.title = cat.title + " – Important Numbers – Taxi Live";
      titleEl.textContent = (cat.icon ? cat.icon + " " : "") + cat.title;
      leadEl.textContent = "Tap a number to call. Tap ⧉ to copy it.";
      q.placeholder = "Search " + cat.title.toLowerCase() + "…";
      renderCategory(cat);
    } else {
      renderHome();
    }
  }

  q.addEventListener("input", render);

  fetch("../api/contacts.php", { cache: "no-store" })
    .then(function (r) { if (!r.ok) throw 0; return r.json(); })
    .then(function (j) { cats = (j.categories || []).filter(function (c) { return c.items.length; }); })
    .catch(function () { cats = fromStatic(); })
    .then(render);
})();
