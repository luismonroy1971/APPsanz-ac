/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'],
      },
      colors: {
        brand: {
          50: '#f0f4f9',
          100: '#dbe4ef',
          200: '#b9cadf',
          300: '#8ea8c9',
          400: '#5f80ab',
          500: '#3f6290',
          600: '#2f4d76',
          700: '#1e3a5f',
          800: '#172e4c',
          900: '#10213a',
          950: '#0a1628',
        },
        gold: {
          50: '#fbf7e9',
          100: '#f5ecc7',
          300: '#e3c766',
          400: '#d6b23e',
          500: '#c9a227',
          600: '#a9861c',
          700: '#856816',
        },
      },
      boxShadow: {
        card: '0 1px 2px rgba(16,33,58,.06), 0 1px 3px rgba(16,33,58,.08)',
      },
    },
  },
  plugins: [],
}
