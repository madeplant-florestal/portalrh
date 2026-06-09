(() => {
  const toUrl = (value) => {
    try {
      return new URL(value);
    } catch {
      return null;
    }
  };

  const generateTrackedUrl = (currentHref, source, medium = 'social', campaign = 'vagas') => {
    const parsed = toUrl(currentHref);
    if (!parsed) return '';
    parsed.searchParams.set('utm_source', source);
    parsed.searchParams.set('utm_medium', medium);
    parsed.searchParams.set('utm_campaign', campaign);
    return parsed.toString();
  };

  const buildShareLinks = (currentHref, title) => {
    const safeTitle = String(title || 'Vagas disponíveis');
    const facebookUrl = generateTrackedUrl(currentHref, 'facebook');
    const linkedinUrl = generateTrackedUrl(currentHref, 'linkedin');
    const twitterUrl = generateTrackedUrl(currentHref, 'twitter');
    const whatsappUrl = generateTrackedUrl(currentHref, 'whatsapp');
    const emailUrl = generateTrackedUrl(currentHref, 'email', 'email');
    return {
      facebook: `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(facebookUrl)}`,
      linkedin: `https://www.linkedin.com/sharing/share-offsite/?url=${encodeURIComponent(linkedinUrl)}`,
      twitter: `https://twitter.com/intent/tweet?text=${encodeURIComponent(safeTitle)}&url=${encodeURIComponent(twitterUrl)}`,
      whatsapp: `https://wa.me/?text=${encodeURIComponent(`${safeTitle} ${whatsappUrl}`)}`,
      email: `mailto:?subject=${encodeURIComponent(safeTitle)}&body=${encodeURIComponent(`Confira esta página: ${emailUrl}`)}`,
      direct: generateTrackedUrl(currentHref, 'link', 'copy'),
      native: generateTrackedUrl(currentHref, 'native', 'share_api'),
    };
  };

  const api = {
    generateTrackedUrl,
    buildShareLinks,
  };

  if (typeof window !== 'undefined') {
    window.ShareUtils = api;
  }
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api;
  }
})();

