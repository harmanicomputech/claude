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

// ---- Detail for the Agenda page -------------------------------------------------
// "said": Dr. Odii's own words, translated from Igbo (BBC News Igbo interview).
// "record": things he has already done, from his biography and ifeanyiodii.com.
export const pillarDetail: Record<string, { said: string[]; record: string[] }> = {
  'day-one': {
    said: [
      'Many people think you must be in office for eight or twenty years before you produce visible results. But if you have a plan and you know the process, change will begin on your first day in office.',
      'A governance plan tells people what your government intends, and makes them begin to trust government. When they trust the government, the government can begin to work for the public.',
    ],
    record: ['More than 20 years building and managing businesses across seven sectors.', 'Founder and Chairman of Orient Global Group; President and CEO of Ultimus Holdings.'],
  },
  poverty: {
    said: [
      'As Governor, within three months, if you see anyone hawking on the street, know that the person is not from Ebonyi, because I will bring out a plan that stops them from hawking on the streets.',
      'That is why government must stand firm on changing the mindset of Ebonyi people and rescuing them from poverty.',
    ],
    record: ['Annual empowerment programmes through his foundation, providing motorcycles, sewing machines, grinding machines, bags of rice and wrappers to families.'],
  },
  women: {
    said: [
      'Support a woman, and you have supported the whole community.',
      'If God gives me victory, and men make up 60 percent of the government, women will be 40 percent of those who work with me, because I know what they are capable of.',
    ],
    record: ['His foundation’s 2017 annual programme was dedicated to education and women’s empowerment.'],
  },
  business: {
    said: ['The second reason I am going into government is to bring out policies that will promote business. I know how to do it.'],
    record: ['Built Orient Global Group and Ultimus Holdings, with businesses in manufacturing, logistics, construction, real estate, healthcare, trade and services.', 'Grew these businesses beyond Nigeria into Sub-Saharan Africa.'],
  },
  farms: {
    said: ['If farmers can farm their land, there will be no trouble.'],
    record: ['Founder of Purity Agro-Allied Ltd., part of Orient Global Group.'],
  },
  youth: {
    said: ['If young people are supported, none of them will go into the bush looking for someone to kill. It is poverty.'],
    record: ['Founded the Anyichuks Unity Cup, a yearly grassroots tournament in Isu for young people.', 'Scholarships for more than 1,000 students at home and abroad.'],
  },
  infrastructure: {
    said: [
      'With my own hands I have built roads in the past. I have provided electricity and built houses for people.',
      'If I can do these things through the plans I use to run my foundation, then I will make it multiply.',
    ],
    record: ['More than 100 homes built for indigent families in rural areas.', 'Six churches built for communities.'],
  },
  'education-health': {
    said: ['I have given people free education, and run medical outreach every year. Governance is for the welfare of the people.'],
    record: [
      'Scholarships for more than 1,000 students, and 10,000 WAEC past-question books for 11 secondary schools in Ebonyi.',
      'A new building for Isu Achara Primary School, with an ICT centre, laboratory and multipurpose hall.',
      'Yearly medical screening and support, including free mobile medical testing and free prescribed drugs.',
    ],
  },
};

// ---- Foundation (from ifeanyiodii.com/philanthropy) -----------------------------
export const waecSchools = [
  'Community Secondary School, Abaomege',
  'Uzem Comprehensive Secondary School, Amangwu Edda',
  'Government Secondary School, Owutu Edda',
  'Itim Secondary School, Edda',
  'Modern Secondary School, Ebunwana Edda',
  'Technical Secondary School, Osu Edda',
  'Akaeze Comprehensive Secondary School, Akaeze',
  'Community Secondary School, Iyioji, Ivo LGA',
  'Ishiagu High School, Ishiagu, Ivo LGA',
  'Modern Secondary School, Ishiagu, Ivo LGA',
  'Echille Secondary School, Amara Ishiagu',
];

export const empowerment2017 = [
  ['50', 'motorcycles'],
  ['50', 'sewing machines'],
  ['50', 'grinding machines'],
  ['1,000', 'bags of rice'],
  ['500', 'wrappers'],
  ['20', 'hair dryers'],
];

// ---- Timeline (dated facts only) -----------------------------------------------
export const timeline = [
  { when: 'Over 20 years', what: 'Builds and manages businesses across seven sectors, growing them from Nigeria into Sub-Saharan Africa.' },
  { when: 'Over 10 years', what: 'Runs the Ebele and Anyichuks Foundation with his wife, supporting and empowering people.' },
  { when: '2016', what: 'The foundation provides free mobile medical testing, free prescribed drugs, empowerment items and scholarships in Ebonyi State.' },
  { when: '2017', what: 'The foundation’s annual programme focuses on education and women’s empowerment, including a new building for Isu Achara Primary School.' },
  { when: '2023', what: 'Runs for Governor of Ebonyi State and pursues the result to the Supreme Court.' },
  { when: '2027', what: 'PDP candidate for Governor of Ebonyi State. Election day is Saturday, 6 February 2027.' },
];

export const sectors = [
  { name: 'Manufacturing', icon: 'factory' },
  { name: 'Logistics', icon: 'truck' },
  { name: 'Construction', icon: 'crane' },
  { name: 'Real estate', icon: 'home' },
  { name: 'Healthcare', icon: 'medical' },
  { name: 'Trade', icon: 'trade' },
  { name: 'Services', icon: 'people' },
];
