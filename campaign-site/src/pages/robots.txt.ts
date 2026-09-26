import { site } from '../config';

export function GET() {
  return new Response(`User-agent: *\nAllow: /\nDisallow: /volunteer.php\nDisallow: /thank-you/\nDisallow: /sign-up-problem/\n\nSitemap: ${new URL('/sitemap.xml', site.url).href}\n`, {
    headers: { 'Content-Type': 'text/plain' },
  });
}
