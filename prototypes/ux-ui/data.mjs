// Deliberately fictional. No database, supplier CSV, prices or real customers.
export const concepts = {
  a: { name: 'Tech Premium', short: 'Calma. Diseño. Presencia.', label: 'A', title: 'Una pequeña pieza.\nUna gran presencia.', intro: 'Memorias USB que hacen espacio para tu marca. Explora el diseño, encuentra tu estilo y prepara tu cotización.', photo: '427', featured: 'Diseño que se queda contigo.' },
  b: { name: 'Comercial dinámico', short: 'Encuentra tu próxima idea.', label: 'B', title: 'Tu marca,\nsiempre presente.', intro: 'Una memoria USB. Muchas formas de hacerla tuya. Encuentra el modelo, el color y la capacidad para tu próximo proyecto.', photo: '373', featured: 'Elige el estilo de tu próxima idea.' },
  c: { name: 'B2B moderno', short: 'De la búsqueda a la cotización.', label: 'C', title: 'Tu siguiente proyecto,\nbien conectado.', intro: 'Consulta modelos y disponibilidad. Selecciona variantes y cantidades. Organiza todo en una sola solicitud de cotización.', photo: '535', featured: 'El catálogo, a tu manera.' },
};
const families = [
  ['Giro', '373', 'Clásicas', 'Un formato giratorio que acompaña las ideas de todos los días.'],
  ['Línea', '391', 'Metálicas', 'Líneas sencillas y un acabado metálico para una presencia discreta.'],
  ['Llave', '427', 'Compactas', 'Un diseño ligero, con una silueta pensada para llevar contigo.'],
  ['Bloque', '517', 'Clásicas', 'Un formato limpio para poner tu identidad en primer plano.'],
  ['Luz', '535', 'Compactas', 'Un cuerpo transparente con un perfil visual diferente.'],
  ['Clave', '553', 'Metálicas', 'Una pequeña pieza de color para un proyecto con personalidad.'],
  ['Origen', '571', 'Naturales', 'Una combinación visual de madera y transparencia.'],
  ['Giro color', '733', 'Clásicas', 'El formato giratorio, con un acento de color.'],
  ['Cuadro', '355', 'Compactas', 'Superficie amplia y geometría sencilla.'],
  ['Veta', '445', 'Naturales', 'Textura cálida en una silueta rectangular.'],
];
export const categories = ['Clásicas','Metálicas','Compactas','Naturales'];
export const products = Array.from({length:72},(_,i)=>{
  const [family,photo,category,description] = families[i%families.length];
  const id = 72-i;
  return {id, model:`DEMO-${String(id).padStart(3,'0')}`, name:`USB ${family}${i>=10?' · serie '+(Math.floor(i/10)+1):''}`, photo, category, description,
    capacity:[8,16,32][Math.floor(i/10)%3], stock:i%13===12?25: [1240,860,2450,520,1840][i%5], color:['Azul','Rojo','Plata','Rojo','Plata','Rojo','Natural','Verde','Blanco','Natural'][i%10]};
});
export const esc = value => String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
export const number = value => Number(value).toLocaleString('es-MX');
export const icon = (name, extra='') => {
  const shapes = { search:'<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/>', arrow:'<path d="M5 12h14m-6-6 6 6-6 6"/>', bag:'<path d="M5 7h14l1 14H4L5 7Z"/><path d="M8 8V6a4 4 0 0 1 8 0v2"/>', grid:'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>', sliders:'<path d="M3 6h18M3 12h18M3 18h18"/><circle cx="8" cy="6" r="2"/><circle cx="16" cy="12" r="2"/><circle cx="8" cy="18" r="2"/>', check:'<path d="m5 12 4 4L19 6"/>', box:'<path d="m12 3 9 5-9 5-9-5 9-5Zm-9 5v10l9 4 9-4V8M12 13v9"/>', doc:'<path d="M6 3h8l4 4v14H6V3Zm8 0v5h4M9 12h6m-6 4h6"/>', menu:'<path d="M4 6h16M4 12h16M4 18h16"/>', close:'<path d="m6 6 12 12M6 18 18 6"/>', plus:'<path d="M12 5v14M5 12h14"/>', chevron:'<path d="m8 5 7 7-7 7"/>', mail:'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/>', clock:'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>', people:'<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3m1-16a3 3 0 0 1 0 6m3 10v-3a5 5 0 0 0-3-4"/>', heart:'<path d="M12 21 3 12C-2 5 7 0 12 7c5-7 14-2 9 5l-9 9Z"/>', sun:'<circle cx="12" cy="12" r="4"/><path d="M12 1v3m0 16v3M1 12h3m16 0h3M4 4l2 2m12 12 2 2M4 20l2-2M18 6l2-2"/>' };
  return `<svg class="icon ${extra}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${shapes[name]||shapes.box}</svg>`;
};
