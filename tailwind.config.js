module.exports = {
  darkMode: ['class', '[data-theme="dark"]'],
  content: [
    './index.php', './api.php', './sc.php', './404.php', './user.php',
    './verify-author.php', './verify-confirm.php',
    './js/**/*.js',
    './admin/**/*.php', './station/**/*.php', './author/**/*.php',
    './src/**/*.css'
  ],
  corePlugins: { preflight: true },
  theme: {
    extend: {
      colors: {
        accent: 'hsl(var(--accent-hue), var(--accent-sat), var(--accent-lightness))',
        'accent-hover': 'hsl(var(--accent-hue), calc(var(--accent-sat) + 10%), calc(var(--accent-lightness) - 10%))',
        'accent-light': 'hsl(var(--accent-hue), var(--accent-sat), 90%)',
        surface: 'var(--surface)',
        border: 'var(--border)',
        ink: 'var(--text)',
        'ink-2': 'var(--text-secondary)',
        'ink-3': 'var(--text-muted)'
      },
      borderRadius: {
        DEFAULT: 'var(--radius)',
        sm: 'var(--radius-sm)'
      },
      boxShadow: {
        sm: 'var(--shadow-sm)',
        DEFAULT: 'var(--shadow)',
        md: 'var(--shadow-md)'
      },
      transitionTimingFunction: { spring: 'cubic-bezier(0.34,1.56,0.64,1)' }
    }
  }
};
