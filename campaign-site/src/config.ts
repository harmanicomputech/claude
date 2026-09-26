// Everything the campaign is likely to change lives here.
// Values in [square brackets] are placeholders: the build prints a warning while any remain.

export const site = {
  // Final domain, no trailing slash. Used for link previews, sitemap and canonical URLs.
  url: 'https://ifeanyiodii.com',
  name: 'Dr. Ifeanyi Chukwuma Odii',
  shortName: 'Ifeanyi Odii',
  office: 'for Governor, Ebonyi State 2027',
  party: 'Peoples Democratic Party (PDP)',
  slogan: 'Change from Day One',
  hashtags: ['#DayOneEbonyi', '#OdiiForEbonyi'],
  description:
    'Dr. Ifeanyi Chukwuma Odii (PDP) for Governor of Ebonyi State, 6 February 2027. Change from Day One: out of poverty, open for business, women at the table. Join the movement.',
  // Election day, West Africa Time.
  electionDate: '2027-02-06T00:00:00+01:00',
  electionDateLabel: 'Saturday, 6 February 2027',
};

export const social = [
  { name: 'X (Twitter)', handle: '@ifeanyiCodii', url: 'https://twitter.com/ifeanyiCodii', icon: 'x' },
  { name: 'Facebook', handle: 'ifeanyiCodii', url: 'https://www.facebook.com/ifeanyiCodii/', icon: 'facebook' },
  { name: 'Instagram', handle: '@ifeanyicodii', url: 'https://www.instagram.com/ifeanyicodii/', icon: 'instagram' },
  { name: 'LinkedIn', handle: 'ifeanyicodii', url: 'https://www.linkedin.com/in/ifeanyicodii/', icon: 'linkedin' },
];

// Public campaign contact details. Never put a private number or address here.
export const contact = {
  office: '[Campaign office address]',
  phone: '[Campaign phone number]',
  email: '[Campaign email address]',
};

export const authorisedBy = '[Name of campaign organisation / principal officer]';
// Data controller named in the privacy notice.
export const dataController = '[Name of campaign organisation]';

export const lgas = [
  'Abakaliki', 'Afikpo North', 'Afikpo South (Edda)', 'Ebonyi', 'Ezza North', 'Ezza South', 'Ikwo',
  'Ishielu', 'Ivo', 'Izzi', 'Ohaozara', 'Ohaukwu', 'Onicha',
];

export const helpOptions = [
  'Canvass in my ward',
  'Serve as a polling unit agent',
  'Share on WhatsApp and social media',
  'Mobilise women',
  'Mobilise youth',
  'Transport and logistics',
  'Professional skills (legal, media, medical, IT)',
];

const placeholders = [contact.office, contact.phone, contact.email, authorisedBy, dataController].filter((v) => v.startsWith('['));
if (placeholders.length && typeof process !== 'undefined') {
  console.warn(`\n⚠  Placeholders still in src/config.ts: ${placeholders.join(', ')}\n`);
}
