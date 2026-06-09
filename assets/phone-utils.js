(() => {
  const digits = (value) => {
    let d = String(value || '').replace(/\D/g, '');
    if (d.startsWith('55') && (d.length === 12 || d.length === 13)) {
      d = d.slice(2);
    }
    return d;
  };

  const normalize = (value) => {
    const d = digits(value);
    if (!/^\d{11}$/.test(d)) return null;
    return d;
  };

  const format = (value) => {
    const d = digits(value).slice(0, 11);
    if (!d) return '';

    const ddd = d.slice(0, 2);
    const p1 = d.slice(2, 7);
    const p2 = d.slice(7, 11);

    let out = `(${ddd}`;
    if (ddd.length === 2) out += ')';
    if (p1.length > 0) out += `${ddd.length === 2 ? ' ' : ''}${p1}`;
    if (p2.length > 0) out += `-${p2}`;
    return out;
  };

  const PhoneUtils = { digits, normalize, format };

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = PhoneUtils;
  } else {
    window.PhoneUtils = PhoneUtils;
  }
})();
