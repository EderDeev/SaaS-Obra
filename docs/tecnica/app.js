(() => {
  const search = document.getElementById('search');
  const pages = [...document.querySelectorAll('[data-page]')];
  const links = [...document.querySelectorAll('nav [data-target]')];
  const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  const content = new Map(pages.map(page => [page.id, normalize(page.textContent)]));
  function filter() {
    const words = normalize(search.value.trim()).split(/\s+/).filter(Boolean);
    let count = 0;
    pages.forEach(page => {
      const visible = words.every(word => content.get(page.id).includes(word));
      page.hidden = !visible;
      const link = links.find(link => link.dataset.target === page.id);
      if (link) link.hidden = !visible;
      if (visible) count++;
    });
    document.getElementById('results').textContent = `${count} capítulo(s) / referência(s)${words.length ? ' encontrado(s)' : ''}`;
    document.getElementById('empty').hidden = count !== 0;
  }
  search.addEventListener('input', filter);
  function active() {
    links.forEach(link => link.classList.toggle('active', link.hash === (location.hash || '#inicio')));
  }
  window.addEventListener('hashchange', () => {
    const page = document.getElementById(location.hash.slice(1));
    if (page?.hidden) { search.value = ''; filter(); page.scrollIntoView(); }
    active();
  });
  document.getElementById('print').addEventListener('click', () => window.print());
  filter(); active();
})();
