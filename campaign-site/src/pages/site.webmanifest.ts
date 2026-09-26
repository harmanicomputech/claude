import { site } from '../config';

export function GET() {
  return new Response(JSON.stringify({
    name: `${site.shortName} for Governor`,
    short_name: 'Odii 2027',
    start_url: '/',
    display: 'browser',
    background_color: '#fbfaf7',
    theme_color: '#0e1712',
    icons: [
      { src: '/media/img/icon-192.png', sizes: '192x192', type: 'image/png' },
      { src: '/media/img/icon-512.png', sizes: '512x512', type: 'image/png' },
    ],
  }, null, 2), { headers: { 'Content-Type': 'application/manifest+json' } });
}
