// node_modules/lucide/dist/esm/createElement.js
var createElement = (tag, attrs, children = []) => {
  const element = document.createElementNS("http://www.w3.org/2000/svg", tag);
  Object.keys(attrs).forEach((name) => {
    element.setAttribute(name, String(attrs[name]));
  });
  if (children.length) {
    children.forEach((child) => {
      const childElement = createElement(...child);
      element.appendChild(childElement);
    });
  }
  return element;
};
var createElement$1 = ([tag, attrs, children]) => createElement(tag, attrs, children);

// node_modules/lucide/dist/esm/replaceElement.js
var getAttrs = (element) => Array.from(element.attributes).reduce((attrs, attr) => {
  attrs[attr.name] = attr.value;
  return attrs;
}, {});
var getClassNames = (attrs) => {
  if (typeof attrs === "string")
    return attrs;
  if (!attrs || !attrs.class)
    return "";
  if (attrs.class && typeof attrs.class === "string") {
    return attrs.class.split(" ");
  }
  if (attrs.class && Array.isArray(attrs.class)) {
    return attrs.class;
  }
  return "";
};
var combineClassNames = (arrayOfClassnames) => {
  const classNameArray = arrayOfClassnames.flatMap(getClassNames);
  return classNameArray.map((classItem) => classItem.trim()).filter(Boolean).filter((value, index, self) => self.indexOf(value) === index).join(" ");
};
var toPascalCase = (string) => string.replace(/(\w)(\w*)(_|-|\s*)/g, (g0, g1, g2) => g1.toUpperCase() + g2.toLowerCase());
var replaceElement = (element, { nameAttr, icons: icons2, attrs }) => {
  const iconName = element.getAttribute(nameAttr);
  if (iconName == null)
    return;
  const ComponentName = toPascalCase(iconName);
  const iconNode = icons2[ComponentName];
  if (!iconNode) {
    return console.warn(
      `${element.outerHTML} icon name was not found in the provided icons object.`
    );
  }
  const elementAttrs = getAttrs(element);
  const [tag, iconAttributes, children] = iconNode;
  const iconAttrs = {
    ...iconAttributes,
    "data-lucide": iconName,
    ...attrs,
    ...elementAttrs
  };
  const classNames = combineClassNames(["lucide", `lucide-${iconName}`, elementAttrs, attrs]);
  if (classNames) {
    Object.assign(iconAttrs, {
      class: classNames
    });
  }
  const svgElement = createElement$1([tag, iconAttrs, children]);
  return element.parentNode?.replaceChild(svgElement, element);
};

// node_modules/lucide/dist/esm/defaultAttributes.js
var defaultAttributes = {
  xmlns: "http://www.w3.org/2000/svg",
  width: 24,
  height: 24,
  viewBox: "0 0 24 24",
  fill: "none",
  stroke: "currentColor",
  "stroke-width": 2,
  "stroke-linecap": "round",
  "stroke-linejoin": "round"
};

// node_modules/lucide/dist/esm/icons/activity.js
var Activity = [
  "svg",
  defaultAttributes,
  [
    [
      "path",
      {
        d: "M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"
      }
    ]
  ]
];

// node_modules/lucide/dist/esm/icons/align-left.js
var AlignLeft = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M15 12H3" }],
    ["path", { d: "M17 18H3" }],
    ["path", { d: "M21 6H3" }]
  ]
];

// node_modules/lucide/dist/esm/icons/arrow-down.js
var ArrowDown = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M12 5v14" }],
    ["path", { d: "m19 12-7 7-7-7" }]
  ]
];

// node_modules/lucide/dist/esm/icons/arrow-left.js
var ArrowLeft = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "m12 19-7-7 7-7" }],
    ["path", { d: "M19 12H5" }]
  ]
];

// node_modules/lucide/dist/esm/icons/arrow-right.js
var ArrowRight = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M5 12h14" }],
    ["path", { d: "m12 5 7 7-7 7" }]
  ]
];

// node_modules/lucide/dist/esm/icons/arrow-up-right.js
var ArrowUpRight = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M7 7h10v10" }],
    ["path", { d: "M7 17 17 7" }]
  ]
];

// node_modules/lucide/dist/esm/icons/bell.js
var Bell = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M10.268 21a2 2 0 0 0 3.464 0" }],
    [
      "path",
      {
        d: "M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"
      }
    ]
  ]
];

// node_modules/lucide/dist/esm/icons/bookmark.js
var Bookmark = [
  "svg",
  defaultAttributes,
  [["path", { d: "m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16z" }]]
];

// node_modules/lucide/dist/esm/icons/briefcase-business.js
var BriefcaseBusiness = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M12 12h.01" }],
    ["path", { d: "M16 6V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2" }],
    ["path", { d: "M22 13a18.15 18.15 0 0 1-20 0" }],
    ["rect", { width: "20", height: "14", x: "2", y: "6", rx: "2" }]
  ]
];

// node_modules/lucide/dist/esm/icons/calendar-clock.js
var CalendarClock = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M21 7.5V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h3.5" }],
    ["path", { d: "M16 2v4" }],
    ["path", { d: "M8 2v4" }],
    ["path", { d: "M3 10h5" }],
    ["path", { d: "M17.5 17.5 16 16.3V14" }],
    ["circle", { cx: "16", cy: "16", r: "6" }]
  ]
];

// node_modules/lucide/dist/esm/icons/calendar-days.js
var CalendarDays = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M8 2v4" }],
    ["path", { d: "M16 2v4" }],
    ["rect", { width: "18", height: "18", x: "3", y: "4", rx: "2" }],
    ["path", { d: "M3 10h18" }],
    ["path", { d: "M8 14h.01" }],
    ["path", { d: "M12 14h.01" }],
    ["path", { d: "M16 14h.01" }],
    ["path", { d: "M8 18h.01" }],
    ["path", { d: "M12 18h.01" }],
    ["path", { d: "M16 18h.01" }]
  ]
];

// node_modules/lucide/dist/esm/icons/chart-no-axes-combined.js
var ChartNoAxesCombined = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M12 16v5" }],
    ["path", { d: "M16 14v7" }],
    ["path", { d: "M20 10v11" }],
    ["path", { d: "m22 3-8.646 8.646a.5.5 0 0 1-.708 0L9.354 8.354a.5.5 0 0 0-.707 0L2 15" }],
    ["path", { d: "M4 18v3" }],
    ["path", { d: "M8 14v7" }]
  ]
];

// node_modules/lucide/dist/esm/icons/check.js
var Check = ["svg", defaultAttributes, [["path", { d: "M20 6 9 17l-5-5" }]]];

// node_modules/lucide/dist/esm/icons/chevron-right.js
var ChevronRight = ["svg", defaultAttributes, [["path", { d: "m9 18 6-6-6-6" }]]];

// node_modules/lucide/dist/esm/icons/circle-alert.js
var CircleAlert = [
  "svg",
  defaultAttributes,
  [
    ["circle", { cx: "12", cy: "12", r: "10" }],
    ["line", { x1: "12", x2: "12", y1: "8", y2: "12" }],
    ["line", { x1: "12", x2: "12.01", y1: "16", y2: "16" }]
  ]
];

// node_modules/lucide/dist/esm/icons/circle-check.js
var CircleCheck = [
  "svg",
  defaultAttributes,
  [
    ["circle", { cx: "12", cy: "12", r: "10" }],
    ["path", { d: "m9 12 2 2 4-4" }]
  ]
];

// node_modules/lucide/dist/esm/icons/clipboard-check.js
var ClipboardCheck = [
  "svg",
  defaultAttributes,
  [
    ["rect", { width: "8", height: "4", x: "8", y: "2", rx: "1", ry: "1" }],
    ["path", { d: "M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2" }],
    ["path", { d: "m9 14 2 2 4-4" }]
  ]
];

// node_modules/lucide/dist/esm/icons/cloud-upload.js
var CloudUpload = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M12 13v8" }],
    ["path", { d: "M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242" }],
    ["path", { d: "m8 17 4-4 4 4" }]
  ]
];

// node_modules/lucide/dist/esm/icons/compass.js
var Compass = [
  "svg",
  defaultAttributes,
  [
    [
      "path",
      {
        d: "m16.24 7.76-1.804 5.411a2 2 0 0 1-1.265 1.265L7.76 16.24l1.804-5.411a2 2 0 0 1 1.265-1.265z"
      }
    ],
    ["circle", { cx: "12", cy: "12", r: "10" }]
  ]
];

// node_modules/lucide/dist/esm/icons/contact-round.js
var ContactRound = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M16 2v2" }],
    ["path", { d: "M17.915 22a6 6 0 0 0-12 0" }],
    ["path", { d: "M8 2v2" }],
    ["circle", { cx: "12", cy: "12", r: "4" }],
    ["rect", { x: "3", y: "4", width: "18", height: "18", rx: "2" }]
  ]
];

// node_modules/lucide/dist/esm/icons/download.js
var Download = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" }],
    ["polyline", { points: "7 10 12 15 17 10" }],
    ["line", { x1: "12", x2: "12", y1: "15", y2: "3" }]
  ]
];

// node_modules/lucide/dist/esm/icons/external-link.js
var ExternalLink = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M15 3h6v6" }],
    ["path", { d: "M10 14 21 3" }],
    ["path", { d: "M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" }]
  ]
];

// node_modules/lucide/dist/esm/icons/eye.js
var Eye = [
  "svg",
  defaultAttributes,
  [
    [
      "path",
      {
        d: "M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"
      }
    ],
    ["circle", { cx: "12", cy: "12", r: "3" }]
  ]
];

// node_modules/lucide/dist/esm/icons/file-check.js
var FileCheck = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z" }],
    ["path", { d: "M14 2v4a2 2 0 0 0 2 2h4" }],
    ["path", { d: "m9 15 2 2 4-4" }]
  ]
];

// node_modules/lucide/dist/esm/icons/file-text.js
var FileText = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z" }],
    ["path", { d: "M14 2v4a2 2 0 0 0 2 2h4" }],
    ["path", { d: "M10 9H8" }],
    ["path", { d: "M16 13H8" }],
    ["path", { d: "M16 17H8" }]
  ]
];

// node_modules/lucide/dist/esm/icons/file-user.js
var FileUser = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M14 2v4a2 2 0 0 0 2 2h4" }],
    ["path", { d: "M15 18a3 3 0 1 0-6 0" }],
    ["path", { d: "M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z" }],
    ["circle", { cx: "12", cy: "13", r: "2" }]
  ]
];

// node_modules/lucide/dist/esm/icons/files.js
var Files = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M20 7h-3a2 2 0 0 1-2-2V2" }],
    ["path", { d: "M9 18a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h7l4 4v10a2 2 0 0 1-2 2Z" }],
    ["path", { d: "M3 7.6v12.8A1.6 1.6 0 0 0 4.6 22h9.8" }]
  ]
];

// node_modules/lucide/dist/esm/icons/history.js
var History = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" }],
    ["path", { d: "M3 3v5h5" }],
    ["path", { d: "M12 7v5l4 2" }]
  ]
];

// node_modules/lucide/dist/esm/icons/house.js
var House = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8" }],
    [
      "path",
      {
        d: "M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"
      }
    ]
  ]
];

// node_modules/lucide/dist/esm/icons/image-up.js
var ImageUp = [
  "svg",
  defaultAttributes,
  [
    [
      "path",
      {
        d: "M10.3 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v10l-3.1-3.1a2 2 0 0 0-2.814.014L6 21"
      }
    ],
    ["path", { d: "m14 19.5 3-3 3 3" }],
    ["path", { d: "M17 22v-5.5" }],
    ["circle", { cx: "9", cy: "9", r: "2" }]
  ]
];

// node_modules/lucide/dist/esm/icons/image.js
var Image = [
  "svg",
  defaultAttributes,
  [
    ["rect", { width: "18", height: "18", x: "3", y: "3", rx: "2", ry: "2" }],
    ["circle", { cx: "9", cy: "9", r: "2" }],
    ["path", { d: "m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" }]
  ]
];

// node_modules/lucide/dist/esm/icons/inbox.js
var Inbox = [
  "svg",
  defaultAttributes,
  [
    ["polyline", { points: "22 12 16 12 14 15 10 15 8 12 2 12" }],
    [
      "path",
      {
        d: "M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"
      }
    ]
  ]
];

// node_modules/lucide/dist/esm/icons/layout-dashboard.js
var LayoutDashboard = [
  "svg",
  defaultAttributes,
  [
    ["rect", { width: "7", height: "9", x: "3", y: "3", rx: "1" }],
    ["rect", { width: "7", height: "5", x: "14", y: "3", rx: "1" }],
    ["rect", { width: "7", height: "9", x: "14", y: "12", rx: "1" }],
    ["rect", { width: "7", height: "5", x: "3", y: "16", rx: "1" }]
  ]
];

// node_modules/lucide/dist/esm/icons/link.js
var Link = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" }],
    ["path", { d: "M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" }]
  ]
];

// node_modules/lucide/dist/esm/icons/list-checks.js
var ListChecks = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "m3 17 2 2 4-4" }],
    ["path", { d: "m3 7 2 2 4-4" }],
    ["path", { d: "M13 6h8" }],
    ["path", { d: "M13 12h8" }],
    ["path", { d: "M13 18h8" }]
  ]
];

// node_modules/lucide/dist/esm/icons/lock-keyhole.js
var LockKeyhole = [
  "svg",
  defaultAttributes,
  [
    ["circle", { cx: "12", cy: "16", r: "1" }],
    ["rect", { x: "3", y: "10", width: "18", height: "12", rx: "2" }],
    ["path", { d: "M7 10V7a5 5 0 0 1 10 0v3" }]
  ]
];

// node_modules/lucide/dist/esm/icons/log-out.js
var LogOut = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" }],
    ["polyline", { points: "16 17 21 12 16 7" }],
    ["line", { x1: "21", x2: "9", y1: "12", y2: "12" }]
  ]
];

// node_modules/lucide/dist/esm/icons/mail-open.js
var MailOpen = [
  "svg",
  defaultAttributes,
  [
    [
      "path",
      {
        d: "M21.2 8.4c.5.38.8.97.8 1.6v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V10a2 2 0 0 1 .8-1.6l8-6a2 2 0 0 1 2.4 0l8 6Z"
      }
    ],
    ["path", { d: "m22 10-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 10" }]
  ]
];

// node_modules/lucide/dist/esm/icons/mail.js
var Mail = [
  "svg",
  defaultAttributes,
  [
    ["rect", { width: "20", height: "16", x: "2", y: "4", rx: "2" }],
    ["path", { d: "m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" }]
  ]
];

// node_modules/lucide/dist/esm/icons/map-pin.js
var MapPin = [
  "svg",
  defaultAttributes,
  [
    [
      "path",
      {
        d: "M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"
      }
    ],
    ["circle", { cx: "12", cy: "10", r: "3" }]
  ]
];

// node_modules/lucide/dist/esm/icons/menu.js
var Menu = [
  "svg",
  defaultAttributes,
  [
    ["line", { x1: "4", x2: "20", y1: "12", y2: "12" }],
    ["line", { x1: "4", x2: "20", y1: "6", y2: "6" }],
    ["line", { x1: "4", x2: "20", y1: "18", y2: "18" }]
  ]
];

// node_modules/lucide/dist/esm/icons/monitor.js
var Monitor = [
  "svg",
  defaultAttributes,
  [
    ["rect", { width: "20", height: "14", x: "2", y: "3", rx: "2" }],
    ["line", { x1: "8", x2: "16", y1: "21", y2: "21" }],
    ["line", { x1: "12", x2: "12", y1: "17", y2: "21" }]
  ]
];

// node_modules/lucide/dist/esm/icons/paperclip.js
var Paperclip = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M13.234 20.252 21 12.3" }],
    [
      "path",
      {
        d: "m16 6-8.414 8.586a2 2 0 0 0 0 2.828 2 2 0 0 0 2.828 0l8.414-8.586a4 4 0 0 0 0-5.656 4 4 0 0 0-5.656 0l-8.415 8.585a6 6 0 1 0 8.486 8.486"
      }
    ]
  ]
];

// node_modules/lucide/dist/esm/icons/plus.js
var Plus = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M5 12h14" }],
    ["path", { d: "M12 5v14" }]
  ]
];

// node_modules/lucide/dist/esm/icons/radio.js
var Radio = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M4.9 19.1C1 15.2 1 8.8 4.9 4.9" }],
    ["path", { d: "M7.8 16.2c-2.3-2.3-2.3-6.1 0-8.5" }],
    ["circle", { cx: "12", cy: "12", r: "2" }],
    ["path", { d: "M16.2 7.8c2.3 2.3 2.3 6.1 0 8.5" }],
    ["path", { d: "M19.1 4.9C23 8.8 23 15.1 19.1 19" }]
  ]
];

// node_modules/lucide/dist/esm/icons/refresh-cw.js
var RefreshCw = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8" }],
    ["path", { d: "M21 3v5h-5" }],
    ["path", { d: "M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16" }],
    ["path", { d: "M8 16H3v5" }]
  ]
];

// node_modules/lucide/dist/esm/icons/scan-search.js
var ScanSearch = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M3 7V5a2 2 0 0 1 2-2h2" }],
    ["path", { d: "M17 3h2a2 2 0 0 1 2 2v2" }],
    ["path", { d: "M21 17v2a2 2 0 0 1-2 2h-2" }],
    ["path", { d: "M7 21H5a2 2 0 0 1-2-2v-2" }],
    ["circle", { cx: "12", cy: "12", r: "3" }],
    ["path", { d: "m16 16-1.9-1.9" }]
  ]
];

// node_modules/lucide/dist/esm/icons/scan-text.js
var ScanText = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M3 7V5a2 2 0 0 1 2-2h2" }],
    ["path", { d: "M17 3h2a2 2 0 0 1 2 2v2" }],
    ["path", { d: "M21 17v2a2 2 0 0 1-2 2h-2" }],
    ["path", { d: "M7 21H5a2 2 0 0 1-2-2v-2" }],
    ["path", { d: "M7 8h8" }],
    ["path", { d: "M7 12h10" }],
    ["path", { d: "M7 16h6" }]
  ]
];

// node_modules/lucide/dist/esm/icons/send.js
var Send = [
  "svg",
  defaultAttributes,
  [
    [
      "path",
      {
        d: "M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"
      }
    ],
    ["path", { d: "m21.854 2.147-10.94 10.939" }]
  ]
];

// node_modules/lucide/dist/esm/icons/settings-2.js
var Settings2 = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M20 7h-9" }],
    ["path", { d: "M14 17H5" }],
    ["circle", { cx: "17", cy: "17", r: "3" }],
    ["circle", { cx: "7", cy: "7", r: "3" }]
  ]
];

// node_modules/lucide/dist/esm/icons/shield-check.js
var ShieldCheck = [
  "svg",
  defaultAttributes,
  [
    [
      "path",
      {
        d: "M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"
      }
    ],
    ["path", { d: "m9 12 2 2 4-4" }]
  ]
];

// node_modules/lucide/dist/esm/icons/sliders-horizontal.js
var SlidersHorizontal = [
  "svg",
  defaultAttributes,
  [
    ["line", { x1: "21", x2: "14", y1: "4", y2: "4" }],
    ["line", { x1: "10", x2: "3", y1: "4", y2: "4" }],
    ["line", { x1: "21", x2: "12", y1: "12", y2: "12" }],
    ["line", { x1: "8", x2: "3", y1: "12", y2: "12" }],
    ["line", { x1: "21", x2: "16", y1: "20", y2: "20" }],
    ["line", { x1: "12", x2: "3", y1: "20", y2: "20" }],
    ["line", { x1: "14", x2: "14", y1: "2", y2: "6" }],
    ["line", { x1: "8", x2: "8", y1: "10", y2: "14" }],
    ["line", { x1: "16", x2: "16", y1: "18", y2: "22" }]
  ]
];

// node_modules/lucide/dist/esm/icons/sparkles.js
var Sparkles = [
  "svg",
  defaultAttributes,
  [
    [
      "path",
      {
        d: "M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"
      }
    ],
    ["path", { d: "M20 3v4" }],
    ["path", { d: "M22 5h-4" }],
    ["path", { d: "M4 17v2" }],
    ["path", { d: "M5 18H3" }]
  ]
];

// node_modules/lucide/dist/esm/icons/sprout.js
var Sprout = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M7 20h10" }],
    ["path", { d: "M10 20c5.5-2.5.8-6.4 3-10" }],
    [
      "path",
      {
        d: "M9.5 9.4c1.1.8 1.8 2.2 2.3 3.7-2 .4-3.5.4-4.8-.3-1.2-.6-2.3-1.9-3-4.2 2.8-.5 4.4 0 5.5.8z"
      }
    ],
    [
      "path",
      { d: "M14.1 6a7 7 0 0 0-1.1 4c1.9-.1 3.3-.6 4.3-1.4 1-1 1.6-2.3 1.7-4.6-2.7.1-4 1-4.9 2z" }
    ]
  ]
];

// node_modules/lucide/dist/esm/icons/square-pen.js
var SquarePen = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" }],
    [
      "path",
      {
        d: "M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"
      }
    ]
  ]
];

// node_modules/lucide/dist/esm/icons/star.js
var Star = [
  "svg",
  defaultAttributes,
  [
    [
      "path",
      {
        d: "M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"
      }
    ]
  ]
];

// node_modules/lucide/dist/esm/icons/trash-2.js
var Trash2 = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M3 6h18" }],
    ["path", { d: "M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6" }],
    ["path", { d: "M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2" }],
    ["line", { x1: "10", x2: "10", y1: "11", y2: "17" }],
    ["line", { x1: "14", x2: "14", y1: "11", y2: "17" }]
  ]
];

// node_modules/lucide/dist/esm/icons/unplug.js
var Unplug = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "m19 5 3-3" }],
    ["path", { d: "m2 22 3-3" }],
    ["path", { d: "M6.3 20.3a2.4 2.4 0 0 0 3.4 0L12 18l-6-6-2.3 2.3a2.4 2.4 0 0 0 0 3.4Z" }],
    ["path", { d: "M7.5 13.5 10 11" }],
    ["path", { d: "M10.5 16.5 13 14" }],
    ["path", { d: "m12 6 6 6 2.3-2.3a2.4 2.4 0 0 0 0-3.4l-2.6-2.6a2.4 2.4 0 0 0-3.4 0Z" }]
  ]
];

// node_modules/lucide/dist/esm/icons/upload.js
var Upload = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" }],
    ["polyline", { points: "17 8 12 3 7 8" }],
    ["line", { x1: "12", x2: "12", y1: "3", y2: "15" }]
  ]
];

// node_modules/lucide/dist/esm/icons/x.js
var X = [
  "svg",
  defaultAttributes,
  [
    ["path", { d: "M18 6 6 18" }],
    ["path", { d: "m6 6 12 12" }]
  ]
];

// node_modules/lucide/dist/esm/lucide.js
var createIcons = ({ icons: icons2 = {}, nameAttr = "data-lucide", attrs = {} } = {}) => {
  if (!Object.values(icons2).length) {
    throw new Error(
      "Please provide an icons object.\nIf you want to use all the icons you can import it like:\n `import { createIcons, icons } from 'lucide';\nlucide.createIcons({icons});`"
    );
  }
  if (typeof document === "undefined") {
    throw new Error("`createIcons()` only works in a browser environment.");
  }
  const elementsToReplace = document.querySelectorAll(`[${nameAttr}]`);
  Array.from(elementsToReplace).forEach(
    (element) => replaceElement(element, { nameAttr, icons: icons2, attrs })
  );
  if (nameAttr === "data-lucide") {
    const deprecatedElements = document.querySelectorAll("[icon-name]");
    if (deprecatedElements.length > 0) {
      console.warn(
        "[Lucide] Some icons were found with the now deprecated icon-name attribute. These will still be replaced for backwards compatibility, but will no longer be supported in v1.0 and you should switch to data-lucide"
      );
      Array.from(deprecatedElements).forEach(
        (element) => replaceElement(element, { nameAttr: "icon-name", icons: icons2, attrs })
      );
    }
  }
};

// Resources/js/api.js
function appUrl(path) {
  const base = document.querySelector('meta[name="app-base-path"]')?.content ?? "";
  return `${base}/${path.replace(/^\/+/, "")}`;
}
async function request(url, { method = "POST", data = null } = {}) {
  const headers = {
    Accept: "application/json",
    "X-CSRF-Token": document.querySelector('meta[name="csrf-token"]')?.content ?? ""
  };
  let body = data;
  if (data !== null && !(data instanceof FormData)) {
    headers["Content-Type"] = "application/json";
    body = JSON.stringify(data);
  }
  const controller = new AbortController();
  const timeout = window.setTimeout(() => controller.abort(), 15e4);
  let response;
  try {
    response = await fetch(url, {
      method,
      headers,
      body: method === "GET" ? null : body,
      credentials: "same-origin",
      signal: controller.signal
    });
  } catch (error) {
    throw new Error(
      error.name === "AbortError" ? "The request timed out. Check its result before retrying." : "The connection was interrupted. Check your network before trying again."
    );
  } finally {
    window.clearTimeout(timeout);
  }
  let result;
  try {
    result = await response.json();
  } catch {
    throw new Error("The server returned an unexpected response. Check the system log.");
  }
  if (!response.ok || !result.ok) {
    if (response.status === 401) {
      window.location.assign(appUrl("/login"));
    }
    throw new Error(result.message ?? "This operation could not be completed.");
  }
  return result;
}
function toast(message, error = false) {
  const region = document.querySelector("#toasts");
  if (!region) return;
  const item = document.createElement("div");
  item.className = `toast${error ? " error" : ""}`;
  item.setAttribute("role", error ? "alert" : "status");
  const text = document.createElement("span");
  text.textContent = message;
  const close = document.createElement("button");
  close.type = "button";
  close.setAttribute("aria-label", "Dismiss notification");
  close.textContent = "\xD7";
  close.addEventListener("click", () => item.remove());
  item.append(text, close);
  region.append(item);
  window.setTimeout(() => item.remove(), error ? 18e3 : 1e4);
}
async function withLoading(button, callback) {
  if (!button || button.disabled) return;
  const original = [...button.childNodes].map((node) => node.cloneNode(true));
  button.disabled = true;
  button.setAttribute("aria-busy", "true");
  button.textContent = button.dataset.loading ?? "Working\u2026";
  try {
    return await callback();
  } finally {
    button.replaceChildren(...original);
    button.disabled = false;
    button.removeAttribute("aria-busy");
  }
}
function showFormError(form, message) {
  const error = form.querySelector(".form-error");
  if (error) {
    error.textContent = message;
    error.hidden = false;
    error.scrollIntoView({ behavior: "smooth", block: "nearest" });
  } else {
    toast(message, true);
  }
}

// Resources/js/tasks.js
var polling = false;
async function trackTask(id) {
  sessionStorage.setItem("jobflow-task", String(id));
  if (polling) return;
  polling = true;
  const region = document.querySelector("#task-region");
  const panel = document.createElement("div");
  panel.className = "task-progress";
  const spinner = document.createElement("span");
  spinner.className = "spinner";
  spinner.setAttribute("aria-hidden", "true");
  const text = document.createElement("span");
  text.textContent = "Task queued. Waiting for the background worker\u2026";
  panel.append(spinner, text);
  region?.replaceChildren(panel);
  let count = 0;
  const check = async () => {
    try {
      const result = await request(appUrl(`/api/tasks/${id}`), { method: "GET" });
      const task = result.data;
      if (task.status === "completed") {
        sessionStorage.removeItem("jobflow-task");
        text.textContent = "Task completed. Updating your workspace\u2026";
        toast("Background task completed successfully.");
        window.setTimeout(() => window.location.reload(), 900);
        return;
      }
      if (task.status === "failed") {
        sessionStorage.removeItem("jobflow-task");
        throw new Error(
          task.last_error ?? "The task failed. See System settings for details."
        );
      }
      const descriptions = {
        discovery: "Checking permitted sources and processing opportunities\u2026",
        analyse_job: "Analysing requirements and comparing your verified profile\u2026",
        prepare_application: "Preparing your tailored CV and cover letter\u2026",
        submit_application: "Submitting through Gmail. Waiting for confirmation\u2026",
        gmail_sync: "Synchronising relevant application correspondence\u2026"
      };
      text.textContent = task.status === "running" ? descriptions[task.type] ?? "Processing your task\u2026" : task.last_error ? `Waiting to retry: ${task.last_error}` : "Queued. The CLI worker must be running to process this task.";
      count += 1;
      if (count < 100) {
        window.setTimeout(check, 3e3);
      } else {
        polling = false;
        spinner.remove();
        text.textContent = "This task is still queued or running. Review its status in Settings \u2192 System.";
      }
    } catch (error) {
      polling = false;
      spinner.remove();
      text.textContent = error.message;
      toast(error.message, true);
    }
  };
  await check();
}
function resumeTask() {
  const id = sessionStorage.getItem("jobflow-task");
  if (id && /^\d+$/.test(id)) trackTask(id);
}

// Resources/js/forms.js
function handleResult(result) {
  if (result.message) toast(result.message);
  if (result.task_id) trackTask(result.task_id);
  if (result.redirect) window.location.assign(result.redirect);
  else if (result.reload) window.location.reload();
}
function initialiseForms() {
  document.addEventListener("submit", async (event) => {
    const form = event.target.closest("form[data-async]");
    if (!form) return;
    event.preventDefault();
    const error = form.querySelector(".form-error");
    if (error) error.hidden = true;
    const button = event.submitter ?? form.querySelector('button[type="submit"], button:not([type])');
    await withLoading(button, async () => {
      try {
        handleResult(await request(form.action, { data: new FormData(form) }));
      } catch (error2) {
        showFormError(form, error2.message);
      }
    });
  });
  document.addEventListener("click", async (event) => {
    const button = event.target.closest("[data-action]");
    if (!button || button.disabled) return;
    event.preventDefault();
    if (button.dataset.confirm && !window.confirm(button.dataset.confirm)) return;
    await withLoading(button, async () => {
      try {
        handleResult(
          await request(button.dataset.action, {
            data: button.dataset.payload ? JSON.parse(button.dataset.payload) : {}
          })
        );
      } catch (error) {
        toast(error.message, true);
      }
    });
  });
  document.querySelectorAll("[data-ai-assist]").forEach((form) => {
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      await withLoading(event.submitter, async () => {
        try {
          const result = await request(form.action, { data: new FormData(form) });
          const output = form.querySelector("[data-ai-result]");
          const data = result.data;
          output.textContent = data.summary ?? data.answers?.join("\n\n") ?? "";
          if (data.uncertainties?.length) {
            output.textContent += `

Needs your review:
${data.uncertainties.join("\n")}`;
          }
          output.hidden = false;
          toast(result.message);
        } catch (error) {
          showFormError(form, error.message);
        }
      });
    });
  });
}

// Resources/js/navigation.js
function initialiseNavigation() {
  const toggle = document.querySelector("#menu-toggle");
  const sidebar = document.querySelector("#sidebar");
  const overlay = document.querySelector("#sidebar-overlay");
  function closeMenu() {
    sidebar?.classList.remove("open");
    toggle?.setAttribute("aria-expanded", "false");
    if (overlay) overlay.hidden = true;
  }
  toggle?.addEventListener("click", () => {
    const open = sidebar.classList.toggle("open");
    toggle.setAttribute("aria-expanded", String(open));
    overlay.hidden = !open;
    if (open) sidebar.querySelector("a")?.focus();
  });
  overlay?.addEventListener("click", closeMenu);
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") closeMenu();
  });
  document.querySelectorAll("[data-toggle-password]").forEach((button) => {
    button.addEventListener("click", () => {
      const input = document.getElementById(button.dataset.togglePassword);
      const visible = input.type === "password";
      input.type = visible ? "text" : "password";
      button.setAttribute("aria-label", visible ? "Hide password" : "Show password");
      button.setAttribute("aria-pressed", String(visible));
    });
  });
  document.querySelectorAll("[data-open-dialog]").forEach((button) => {
    button.addEventListener(
      "click",
      () => document.getElementById(button.dataset.openDialog)?.showModal()
    );
  });
  document.querySelectorAll("[data-close-dialog]").forEach((button) => {
    button.addEventListener("click", () => button.closest("dialog").close());
  });
  document.querySelectorAll("[data-filter-form]").forEach((form) => {
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      const url = new URL(form.action || window.location.href);
      url.search = new URLSearchParams(new FormData(form)).toString();
      const region = document.querySelector("[data-filter-results]");
      region.setAttribute("aria-busy", "true");
      try {
        const response = await fetch(url, { credentials: "same-origin" });
        if (response.redirected) {
          window.location.assign(response.url);
          return;
        }
        if (!response.ok) throw new Error("The filtered view could not be loaded.");
        const page = new DOMParser().parseFromString(await response.text(), "text/html");
        const results = page.querySelector("[data-filter-results]");
        if (!results) throw new Error("The server returned an unexpected page.");
        region.replaceChildren(...results.childNodes);
        history.replaceState({}, "", url);
        document.dispatchEvent(new Event("jobflow:icons"));
      } catch (error) {
        toast(error.message, true);
      } finally {
        region.removeAttribute("aria-busy");
      }
    });
  });
}

// Resources/js/imports.js
function populateJob(data) {
  const form = document.querySelector("#job-details-form");
  if (!form) return;
  for (const [name, value] of Object.entries(data)) {
    const field = form.elements.namedItem(name);
    if (field && field.type !== "checkbox") field.value = value ?? "";
  }
  form.elements.namedItem("verified").checked = false;
  const notice = document.querySelector("#extraction-notice");
  notice.hidden = false;
  document.querySelector("#extraction-uncertainties").textContent = data.uncertainties?.length ? `Needs your review: ${data.uncertainties.join(" ")}` : "Verify all information against the original advertisement before saving.";
  form.scrollIntoView({ behavior: "smooth", block: "start" });
}
function initialiseImports() {
  document.querySelectorAll("[data-selected-value]").forEach((select) => {
    if (select.dataset.selectedValue) select.value = select.dataset.selectedValue;
  });
  const tabs = [...document.querySelectorAll("[data-import-tab]")];
  function selectTab(tab, focus = false) {
    for (const button of tabs) {
      const selected = button === tab;
      button.classList.toggle("selected", selected);
      button.setAttribute("aria-selected", String(selected));
      button.tabIndex = selected ? 0 : -1;
      const method = button.dataset.importTab;
      if (method !== "manual") {
        document.querySelector(`#import-${method}`).hidden = !selected;
      }
    }
    if (focus) tab.focus();
  }
  tabs.forEach((tab, index) => {
    tab.addEventListener("click", () => selectTab(tab));
    tab.addEventListener("keydown", (event) => {
      if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;
      event.preventDefault();
      const next = event.key === "Home" ? 0 : event.key === "End" ? tabs.length - 1 : (index + (event.key === "ArrowRight" ? 1 : -1) + tabs.length) % tabs.length;
      selectTab(tabs[next], true);
    });
  });
  if (tabs.length) selectTab(tabs[0]);
  document.querySelectorAll("[data-extract-form]").forEach((form) => {
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      form.querySelector(".form-error").hidden = true;
      await withLoading(event.submitter, async () => {
        try {
          const result = await request(appUrl("/api/jobs/extract"), {
            data: new FormData(form)
          });
          populateJob(result.data);
          toast(result.message);
        } catch (error) {
          showFormError(form, error.message);
        }
      });
    });
  });
  document.querySelector("[data-poster-form]")?.addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.target;
    form.querySelector(".form-error").hidden = true;
    await withLoading(event.submitter, async () => {
      try {
        const uploaded = await request(appUrl("/api/documents"), {
          data: new FormData(form)
        });
        document.querySelector("#job-details-form").elements.namedItem("poster_document_id").value = uploaded.document.id;
        toast("Poster stored privately. Extracting the advertisement\u2026");
        const extracted = await request(appUrl("/api/jobs/extract"), {
          data: { type: "poster", document_id: uploaded.document.id }
        });
        populateJob(extracted.data);
        toast(extracted.message);
      } catch (error) {
        showFormError(form, `${error.message} You can enter the details manually below.`);
      }
    });
  });
}

// Resources/js/profile.js
var recordSequence = Date.now();
function addRecord(kind, data = {}) {
  const template = document.querySelector(`#template-${kind}`);
  const list = document.querySelector(`[data-record-list="${kind}"]`);
  if (!template || !list) return;
  const holder = document.createElement("template");
  holder.innerHTML = template.innerHTML.replaceAll("__INDEX__", String(recordSequence++));
  const fragment = holder.content;
  for (const field of fragment.querySelectorAll("[data-record-field]")) {
    field.value = data[field.dataset.recordField] ?? "";
  }
  list.append(fragment);
  document.dispatchEvent(new Event("jobflow:icons"));
}
function initialiseProfile() {
  document.addEventListener("click", (event) => {
    const add = event.target.closest("[data-add-record]");
    if (add) addRecord(add.dataset.addRecord);
    event.target.closest("[data-remove-record]")?.closest("[data-career-record]")?.remove();
  });
  document.querySelectorAll("[data-profile-import]").forEach((button) => {
    button.addEventListener("click", async () => {
      if (!window.confirm(
        "Extract a draft from this CV and replace the fields currently on screen? Your saved profile changes only when you save the form."
      ))
        return;
      await withLoading(button, async () => {
        try {
          const result = await request(
            appUrl(`/api/documents/${button.dataset.profileImport}/profile`),
            { data: {} }
          );
          const form = document.querySelector("#profile-form");
          for (const name of ["full_name", "email", "phone", "location", "summary"]) {
            form.elements.namedItem(name).value = result.data[name] ?? "";
          }
          form.elements.namedItem("skills").value = result.data.skills.join(", ");
          form.elements.namedItem("verified").checked = false;
          for (const kind of ["employment", "education"]) {
            document.querySelector(`[data-record-list="${kind}"]`).replaceChildren();
            for (const record of result.data[kind]) addRecord(kind, record);
          }
          toast(
            `Draft imported. ${result.data.uncertainties.join(" ")} Review every field before saving.`
          );
          form.scrollIntoView({ behavior: "smooth", block: "start" });
        } catch (error) {
          toast(error.message, true);
        }
      });
    });
  });
}

// Resources/js/app.js
var icons = {
  Activity,
  AlignLeft,
  ArrowDown,
  ArrowLeft,
  ArrowRight,
  ArrowUpRight,
  Bell,
  Bookmark,
  BriefcaseBusiness,
  CalendarClock,
  CalendarDays,
  Check,
  ChevronRight,
  CircleAlert,
  CircleCheck,
  ClipboardCheck,
  CloudUpload,
  Compass,
  ContactRound,
  Download,
  ExternalLink,
  Eye,
  FileCheck,
  Files,
  FileText,
  FileUser,
  House,
  Image,
  ImageUp,
  Inbox,
  Link,
  ListChecks,
  LockKeyhole,
  LogOut,
  Mail,
  MailOpen,
  Menu,
  Monitor,
  Paperclip,
  Plus,
  Radio,
  RefreshCw,
  ScanSearch,
  ScanText,
  Send,
  Settings2,
  ShieldCheck,
  SlidersHorizontal,
  Sparkles,
  Sprout,
  SquarePen,
  Star,
  Trash2,
  Unplug,
  Upload,
  X,
  MapPin,
  LayoutDashboard,
  ChartNoAxesCombined,
  History
};
var renderIcons = () => createIcons({ icons });
renderIcons();
document.addEventListener("jobflow:icons", renderIcons);
initialiseForms();
initialiseNavigation();
initialiseImports();
initialiseProfile();
resumeTask();
/*! Bundled license information:

lucide/dist/esm/createElement.js:
lucide/dist/esm/replaceElement.js:
lucide/dist/esm/defaultAttributes.js:
lucide/dist/esm/icons/activity.js:
lucide/dist/esm/icons/align-left.js:
lucide/dist/esm/icons/arrow-down.js:
lucide/dist/esm/icons/arrow-left.js:
lucide/dist/esm/icons/arrow-right.js:
lucide/dist/esm/icons/arrow-up-right.js:
lucide/dist/esm/icons/bell.js:
lucide/dist/esm/icons/bookmark.js:
lucide/dist/esm/icons/briefcase-business.js:
lucide/dist/esm/icons/calendar-clock.js:
lucide/dist/esm/icons/calendar-days.js:
lucide/dist/esm/icons/chart-no-axes-combined.js:
lucide/dist/esm/icons/check.js:
lucide/dist/esm/icons/chevron-right.js:
lucide/dist/esm/icons/circle-alert.js:
lucide/dist/esm/icons/circle-check.js:
lucide/dist/esm/icons/clipboard-check.js:
lucide/dist/esm/icons/cloud-upload.js:
lucide/dist/esm/icons/compass.js:
lucide/dist/esm/icons/contact-round.js:
lucide/dist/esm/icons/download.js:
lucide/dist/esm/icons/external-link.js:
lucide/dist/esm/icons/eye.js:
lucide/dist/esm/icons/file-check.js:
lucide/dist/esm/icons/file-text.js:
lucide/dist/esm/icons/file-user.js:
lucide/dist/esm/icons/files.js:
lucide/dist/esm/icons/history.js:
lucide/dist/esm/icons/house.js:
lucide/dist/esm/icons/image-up.js:
lucide/dist/esm/icons/image.js:
lucide/dist/esm/icons/inbox.js:
lucide/dist/esm/icons/layout-dashboard.js:
lucide/dist/esm/icons/link.js:
lucide/dist/esm/icons/list-checks.js:
lucide/dist/esm/icons/lock-keyhole.js:
lucide/dist/esm/icons/log-out.js:
lucide/dist/esm/icons/mail-open.js:
lucide/dist/esm/icons/mail.js:
lucide/dist/esm/icons/map-pin.js:
lucide/dist/esm/icons/menu.js:
lucide/dist/esm/icons/monitor.js:
lucide/dist/esm/icons/paperclip.js:
lucide/dist/esm/icons/plus.js:
lucide/dist/esm/icons/radio.js:
lucide/dist/esm/icons/refresh-cw.js:
lucide/dist/esm/icons/scan-search.js:
lucide/dist/esm/icons/scan-text.js:
lucide/dist/esm/icons/send.js:
lucide/dist/esm/icons/settings-2.js:
lucide/dist/esm/icons/shield-check.js:
lucide/dist/esm/icons/sliders-horizontal.js:
lucide/dist/esm/icons/sparkles.js:
lucide/dist/esm/icons/sprout.js:
lucide/dist/esm/icons/square-pen.js:
lucide/dist/esm/icons/star.js:
lucide/dist/esm/icons/trash-2.js:
lucide/dist/esm/icons/unplug.js:
lucide/dist/esm/icons/upload.js:
lucide/dist/esm/icons/x.js:
lucide/dist/esm/lucide.js:
  (**
   * @license lucide v0.468.0 - ISC
   *
   * This source code is licensed under the ISC license.
   * See the LICENSE file in the root directory of this source tree.
   *)
*/
