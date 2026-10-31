
(function(){
  const map = [[/Bekfontein Boerdery/g,'Plaashek'],[/Bekfontein/g,'Plaashek'],[/Laughing Waters/g,'Plaashek'],[/Boord/g,'Plaashek']];
  function fix(s){ return map.reduce((t,[re,to])=>t.replace(re,to), s); }
  function walk(root){
    const w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    let n; while((n = w.nextNode())){ const v = n.nodeValue; const f = fix(v); if (f !== v) n.nodeValue = f; }
    root.querySelectorAll('[placeholder],[title],[aria-label],input').forEach(el=>{
      for (const a of ['placeholder','title','aria-label']) { const v = el.getAttribute(a); if (v) { const f = fix(v); if (f!==v) el.setAttribute(a,f); } }
      if (el.type === 'text' && el.value) { const f = fix(el.value); if (f !== el.value) el.value = f; }
    });
  }
  window.__plaashek = () => { walk(document.body); document.title = fix(document.title); };
  window.__plaashek();
  if (!window.__plaashekObs) { window.__plaashekObs = new MutationObserver(() => window.__plaashek()); window.__plaashekObs.observe(document.body, {subtree:true, childList:true, characterData:true}); }
})();