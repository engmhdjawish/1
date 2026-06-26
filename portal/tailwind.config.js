/** @type {import('tailwindcss').Config} */
module.exports = {
  darkMode: 'class',
  content: [
    './views/**/*.php',
    './public/**/*.php',
    './public/assets/**/*.js',
  ],
  theme: {
    extend: {
      colors: {
        primary: '#D81921',
        'surface-bg': '#f6f6f8',
        'surface-card': '#ffffff',
        'surface-white': '#ffffff',
        'surface-low': '#f3f3f5',
        'text-main': '#111827',
        'text-muted': '#4b5563',
        'border-subtle': '#E5E7EB',
        'status-active': '#28A745',
        'status-rejected': '#EF4444',
        'status-pending': '#FFC107',
      },
    },
  },
  plugins: [],
};
