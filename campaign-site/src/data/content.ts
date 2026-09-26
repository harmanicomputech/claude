// Approved campaign content. Agenda pillars were approved on 26 Sept 2026.
// Quotes are Dr. Odii's own words from the BBC News Igbo interview, translated from Igbo.

export const facts = [
  { value: 20, suffix: '+', label: 'Years building businesses' },
  { value: 7, suffix: '', label: 'Sectors: manufacturing, logistics, construction, real estate, healthcare, trade and services' },
  { value: 100, suffix: '+', label: 'Homes built for the indigent' },
  { value: 6, suffix: '', label: 'Churches built' },
  { value: 3, suffix: '', label: 'Floors of primary and secondary school' },
  { value: 1000, suffix: '+', label: 'Scholarships, at home and abroad' },
  { text: 'Yearly', label: 'Free medical screening and support' },
];

export type Pillar = { id: string; title: string; body: string; icon: string; image?: string; alt?: string; quote?: string };

export const pillars: Pillar[] = [
  {
    id: 'day-one',
    title: 'Change from Day One',
    body: 'Real change does not need eight or twenty years; with a clear plan, it starts on the first day in office. A published governance plan tells every Ebonyi citizen what the government intends, and builds the trust that makes delivery possible.',
    icon: 'sunrise',
    image: 'meeting',
    alt: 'Dr. Odii speaking into a microphone at a meeting table',
    quote: 'If you have a plan and you know the process, change will begin on your first day in office.',
  },
  {
    id: 'poverty',
    title: 'Out of Poverty, Off the Streets',
    body: 'Lifting Ebonyi families out of poverty is the heart of this mission. A plan in the first three months will aim to give Ebonyi people who hawk on the streets a better path to earning a living.',
    icon: 'hands',
    image: 'fdn-machines',
    alt: 'Grinding machines and generators lined up for an empowerment programme',
  },
  {
    id: 'women',
    title: 'Women at the Table',
    body: 'Women will make up 40 percent of the people who serve in his government. There will be dedicated support for widows and mothers, because “support a woman, and you have supported the whole community.”',
    icon: 'people',
    image: 'fdn-celebration',
    alt: 'Women dancing in celebration at a foundation empowerment event',
  },
  {
    id: 'business',
    title: 'Open for Business',
    body: 'Remove the bottlenecks that keep investors away, and bring in policies that make Ebonyi one of the easiest states in which to do business. Add value locally to the state’s natural resources, such as its solid minerals.',
    icon: 'key',
    image: 'office',
    alt: 'Dr. Odii in a grey suit, arms folded, in an office',
  },
  {
    id: 'farms',
    title: 'Farms, Food and Agro-processing',
    body: 'Farmers must be able to work their land in peace. Support for rice, yam and cassava farmers, and processing close to the farms, keeps the value and the jobs in Ebonyi.',
    icon: 'grain',
    image: 'fdn-rice',
    alt: 'Hundreds of bags of rice stacked for distribution',
  },
  {
    id: 'youth',
    title: 'Youth and Security Through Prosperity',
    body: 'Poverty fuels insecurity. Supporting young people with skills, work and opportunity is the most lasting way to make communities safe. Grassroots sport, following the Unity Cup’s example, is part of this.',
    icon: 'shield',
    image: 'with-supporters',
    alt: 'Dr. Odii, smiling, arrives at an event wearing a red, white and black cap',
  },
  {
    id: 'infrastructure',
    title: 'Roads, Power and Homes',
    body: 'Take what his foundation has done, building roads, providing electricity and building homes, and multiply it across the state. Add reliable clean water for every community.',
    icon: 'road',
  },
  {
    id: 'education-health',
    title: 'Education and Health for All',
    body: 'Scale up his free education, scholarships and yearly medical outreach into state policy: better schools, and primary healthcare within reach of every community.',
    icon: 'book',
    image: 'fdn-students',
    alt: 'Secondary school students seated outdoors at a book presentation',
  },
];

export const quotes = [
  'Governance is for the welfare of the people.',
  'Support a woman, and you have supported the whole community.',
  'If you have a plan and you know the process, change will begin on your first day in office.',
];

export const companies = [
  {
    group: 'Orient Global Group',
    role: 'Founder and Chairman',
    subsidiaries: ['Orient Global Manufacturing', 'Orient Haulage & Logistics', 'Purity Agro-Allied Ltd.'],
  },
  {
    group: 'Ultimus Holdings',
    role: 'President and CEO',
    subsidiaries: ['Ultimus Construction', 'Ultimus Properties', 'Ultimus Global Integrated (The Classroom by Ultimus, Viarmor Healthcare Ltd.)'],
  },
];

export const education = [
  { title: 'Doctor of Science, Strategic Business Management & Corporate Governance', place: 'European American University, Republic of Panama (conferred)' },
  { title: 'Chief Executive Programme', place: 'Lagos Business School' },
  { title: 'B.Sc. Business Administration', place: 'National Open University of Nigeria' },
];

export const boards = [
  'Member, Governing Council, Lagos State University',
  'Board member, Prosperis Holdings',
];

export type GalleryItem = { image?: string; video?: string; alt: string; caption: string; wide?: boolean };

export const gallery: GalleryItem[] = [
  { video: 'bbc-igbo-interview', alt: 'Dr. Odii speaking in an interview with BBC News Igbo', caption: 'Interview with BBC News Igbo (in Igbo, English captions)' },
  { image: 'ceremony', alt: 'Dr. Odii in a red cap and white attire, both arms raised in celebration', caption: 'Portrait', wide: true },
  { image: 'agbada', alt: 'Dr. Odii in a black agbada and red cap', caption: 'Portrait' },
  { image: 'with-supporters', alt: 'Dr. Odii arriving at an event with his team', caption: 'On the campaign trail', wide: true },
  { image: 'fedora', alt: 'Dr. Odii in a black hat, smiling', caption: 'Portrait' },
  { image: 'fdn-celebration', alt: 'Women celebrating at the foundation’s annual empowerment programme', caption: 'Ebele and Anyichuks Foundation empowerment programme', wide: true },
  { image: 'white-cap', alt: 'Dr. Odii in a white shirt and cap, pointing and smiling', caption: 'Portrait' },
  { image: 'fdn-books', alt: 'WAEC past-question books stacked in front of students', caption: 'WAEC past questions for Ebonyi schools' },
  { video: 'interview-2022', alt: 'Dr. Odii speaking in an interview', caption: 'Interview, 2022: ease of doing business' },
  { image: 'evening-event', alt: 'Dr. Odii in a white hat greeting a guest at an evening event', caption: 'At an evening event', wide: true },
  { image: 'blue-suit', alt: 'Dr. Odii in a blue suit, smiling and pointing', caption: 'Portrait' },
  { image: 'fdn-women', alt: 'Women seated at the foundation’s empowerment programme', caption: 'Women at the empowerment programme', wide: true },
  { image: 'grey-suit', alt: 'Dr. Odii in a grey suit, arms folded', caption: 'Portrait' },
  { image: 'fdn-school-visit', alt: 'Students and teachers gathered for a book presentation', caption: 'School book presentation' },
  { image: 'handshake-1', alt: 'Dr. Odii shaking hands with a guest at an event', caption: 'At an event' },
  { image: 'fdn-aerial', alt: 'Aerial view of the empowerment programme grounds with motorcycles and rice', caption: 'Annual empowerment programme', wide: true },
  { image: 'black-cap', alt: 'Dr. Odii in a black cap and shirt, smiling', caption: 'Portrait', wide: true },
];
