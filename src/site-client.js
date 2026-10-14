/* PLC Market: sitenin kendi sunucusu (api/v1.php) için istemci katmanı.
   Sayfadaki kod claude.ai'deki window.claude arayüzünü kullanır; bu dosya aynı arayüzü
   İHS'deki PHP + MySQL sunucusuna bağlar. Ayrıca giriş / üyelik işlemleri için window.PLC_SITE sağlar. */
(function () {
  "use strict";
  var API = "/api/v1.php";
  function call(op, body) {
    return fetch(API, {
      method: "POST", credentials: "same-origin",
      headers: { "Content-Type": "application/json", "X-PLC": "1" },
      body: JSON.stringify(Object.assign({ op: op }, body || {}))
    }).then(function (r) {
      return r.json().catch(function () { return null; }).then(function (j) {
        if (!r.ok || !j || j.error) {
          var e = new Error((j && j.error && j.error.message) || ("Sunucu hatası (" + r.status + ")"));
          e.code = (j && j.error && j.error.code) || "unavailable"; e.status = r.status; throw e;
        }
        return j;
      });
    });
  }
  var clone = function (o) { return o == null ? o : JSON.parse(JSON.stringify(o)); };
  var rid = function () { var a = new Uint8Array(10); crypto.getRandomValues(a); return Array.from(a, function (b) { return ("0" + b.toString(16)).slice(-2); }).join(""); };
  var USER = null;
  var ready = call("auth.me").then(function (j) { USER = j.user; return USER; }).catch(function () { USER = null; return null; });

  /* ---- canlı izleme: tüm abonelikler tek istekte yoklanır ---- */
  var W = { docs: new Map(), cols: new Map(), timer: null, busy: false, gen: 0 };
  function schedule(ms) {
    clearTimeout(W.timer);
    W.timer = setTimeout(poll, ms != null ? ms : (document.hidden ? 300000 : (USER ? 30000 : 90000)));
  }
  function poll() {
    if (W.busy) { schedule(1000); return; }
    var docs = {}, cols = {}, n = 0;
    W.docs.forEach(function (w, p) { docs[p] = w.v; n++; });
    W.cols.forEach(function (w, p) { cols[p] = w.v; n++; });
    if (!n) return;
    W.busy = true;
    var gen = W.gen;
    call("db.watch", { docs: docs, cols: cols }).then(function (j) {
      // Yoklama sürerken bu sayfa yazdıysa yanıt eskimiş olabilir: atla, hemen yeniden yokla
      if (gen !== W.gen) { W.busy = false; schedule(300); return "skip"; }
      // Yalnızca içerik gerçekten değiştiyse bildir; kendi yazdığımızın yankısı ekranı yeniden çizdirmesin
      var same = function (a, b) { return JSON.stringify(a) === JSON.stringify(b); };
      Object.keys(j.docs || {}).forEach(function (p) {
        var w = W.docs.get(p); if (!w) return;
        var e = j.docs[p], old = w.e; w.v = e.version || 0; w.e = e;
        if (old && !!old.exists === !!e.exists && same(old.data, e.data)) return;
        w.subs.forEach(function (f) { f(e); });
      });
      Object.keys(j.cols || {}).forEach(function (p) {
        var w = W.cols.get(p); if (!w) return;
        var e = j.cols[p], old = w.e, list = e.docs || []; w.v = e.sig; w.e = list;
        var strip = function (l) { return (l || []).map(function (d) { return [d.id, d.data]; }); };
        if (old && same(strip(old), strip(list))) return;
        w.subs.forEach(function (f) { f(list); });
      });
    }).catch(function () {}).then(function (r) { if (r === "skip") return; W.busy = false; schedule(); });
  }
  document.addEventListener("visibilitychange", function () { if (!document.hidden) schedule(50); });
  function sub(map, path, fn, init) {
    var w = map.get(path);
    if (!w) { w = { v: init, e: null, subs: new Set() }; map.set(path, w); }
    w.subs.add(fn);
    if (w.e !== null) setTimeout(function () { fn(w.e); }, 0);
    schedule(40);
    return function () { w.subs.delete(fn); if (!w.subs.size) map.delete(path); };
  }
  function parentOf(p) { var i = p.lastIndexOf("/"); return i < 0 ? "" : p.slice(0, i); }
  function local(path, data, version, del) {
    W.gen++;
    var w = W.docs.get(path);
    if (w) { w.e = del ? { exists: false, version: 0 } : { exists: true, data: clone(data), version: version || 0 }; w.v = del ? 0 : (version || w.v); var e = w.e; w.subs.forEach(function (f) { f(e); }); }
    var c = W.cols.get(parentOf(path));
    if (c && c.e) {
      var id = path.slice(path.lastIndexOf("/") + 1);
      var list = c.e.filter(function (d) { return d.id !== id; });
      if (!del) { list.push({ id: id, data: clone(data), version: version || 0 }); list.sort(function (a, b) { return a.id < b.id ? -1 : a.id > b.id ? 1 : 0; }); }
      c.e = list; c.v = "yerel"; c.subs.forEach(function (f) { f(list); });
    }
    schedule(800);
  }
  var meta = { fromCache: false, hasPendingWrites: false };
  function snapDoc(path, e) {
    var ok = !!(e && e.exists);
    return { id: path.slice(path.lastIndexOf("/") + 1), exists: ok, data: function () { return ok ? clone(e.data) : undefined; }, metadata: meta };
  }
  function snapCol(path, list) {
    var docs = (list || []).map(function (d) { return snapDoc(path + "/" + d.id, { exists: true, data: d.data }); });
    return { docs: docs, size: docs.length, empty: !docs.length, metadata: meta,
      docChanges: function () { return docs.map(function (d, i) { return { type: "added", doc: d, oldIndex: -1, newIndex: i }; }); } };
  }
  function checkPath(path, doc) {
    var n = String(path).split("/").length;
    if (!path || (doc ? n % 2 : !(n % 2))) throw new TypeError("Geçersiz yol: " + path);
  }
  function docRef(path) {
    checkPath(path, true);
    return {
      id: path.slice(path.lastIndexOf("/") + 1), path: path,
      get: function () { return call("db.get", { path: path }).then(function (j) { return snapDoc(path, j); }); },
      set: function (data) { return call("db.set", { path: path, data: data }).then(function (j) { local(path, j.data || data, j.version); }); },
      update: function (data) {
        return call("db.update", { path: path, data: data }).then(function () { return call("db.get", { path: path }); })
          .then(function (g) { local(path, g.data, g.version); });
      },
      delete: function () { return call("db.delete", { path: path }).then(function () { local(path, null, 0, true); }); },
      onSnapshot: function (next) { return sub(W.docs, path, function (e) { next(snapDoc(path, e)); }, -1); },
      collection: function (c) { return colRef(path + "/" + c); }
    };
  }
  function colRef(path) {
    checkPath(path, false);
    var q = {
      path: path,
      doc: function (id) { return docRef(path + "/" + (id || rid())); },
      get: function () { return call("db.list", { path: path }).then(function (j) { return snapCol(path, j.docs); }); },
      onSnapshot: function (next) { return sub(W.cols, path, function (list) { next(snapCol(path, list)); }, "?"); },
      add: function (d) { var r = docRef(path + "/" + rid()); return r.set(d).then(function () { return r; }); },
      where: function () { return q; }, orderBy: function () { return q; }, limit: function () { return q; }
    };
    return q;
  }
  var db = { doc: docRef, collection: colRef };

  var assets = {
    upload: function (blob, o) {
      var type = (o && o.type) || blob.type || "";
      return fetch("/api/asset.php?type=" + encodeURIComponent(type), {
        method: "POST", credentials: "same-origin", headers: { "X-PLC": "1", "Content-Type": type || "application/octet-stream" }, body: blob
      }).then(function (r) {
        return r.json().catch(function () { return null; }).then(function (j) {
          if (!r.ok || !j || j.error) { var e = new Error((j && j.error && j.error.message) || "Yükleme başarısız"); e.code = (j && j.error && j.error.code) || (r.status === 413 ? "too_large" : "upstream_error"); throw e; }
          return j;
        });
      });
    },
    list: function () { return call("asset.list"); },
    delete: function (ref) { return call("asset.delete", { id: String(ref) }); }
  };
  var downloads = {
    save: function (x) {
      var blob = x.data instanceof Blob ? x.data : new Blob([x.data], { type: "text/plain;charset=utf-8" });
      var a = document.createElement("a"); a.href = URL.createObjectURL(blob); a.download = x.filename || "dosya";
      document.body.appendChild(a); a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 2000);
      return Promise.resolve({});
    }
  };
  var user = {
    canEdit: function () { return ready.then(function () { return !!(USER && USER.admin); }); },
    isOwner: function () { return ready.then(function () { return !!(USER && USER.admin); }); },
    can: function () { return ready.then(function () { return !!(USER && USER.admin); }); },
    id: function () { return ready.then(function () { return USER ? USER.uid : null; }); },
    me: function () { return ready.then(function () { return { id: USER ? USER.uid : null, name: "", email: USER ? USER.email : "" }; }); }
  };

  window.claude = {
    use: function (name) {
      return ready.then(function () {
        if (name === "db") return db;
        if (name === "user") return user;
        if (name === "downloads") return downloads;
        if (name === "assets") return USER && USER.admin ? assets : null;
        return null;
      });
    }
  };
  window.PLC_SITE = {
    ready: ready,
    user: function () { return USER; },
    login: function (email, pass) { return call("auth.login", { email: email, pass: pass }).then(function (j) { USER = j.user; return USER; }); },
    register: function (email, pass) { return call("auth.register", { email: email, pass: pass }).then(function (j) { USER = j.user; return USER; }); },
    logout: function () { return call("auth.logout").then(function () { USER = null; }); },
    passwd: function (oldp, newp) { return call("auth.passwd", { old: oldp, new: newp }); },
    resetPw: function (uid) { return call("admin.resetpw", { uid: uid }).then(function (j) { return j.temp; }); },
    defaultXlsx: function () { return call("admin.defaultxlsx").then(function (j) { return j.b64; }); }
  };
})();
