import media from '../data/media.json';

type Info = { widths: number[]; width: number; height: number };
export const images = media.images as Record<string, Info>;

export function srcset(name: string, ext: string) {
  const info = images[name];
  if (!info) throw new Error(`Unknown image "${name}"`);
  return info.widths.map((w) => `/media/img/${name}-${w}.${ext} ${w}w`).join(', ');
}
