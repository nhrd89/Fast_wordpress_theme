# PinLightning

An ultrafast, lightweight WordPress theme built for speed and simplicity.

## Features

- Zero dependencies - no jQuery, no external libraries
- Minimal, clean design with CSS custom properties for easy theming
- Responsive and mobile-first
- Customizer support with accent color control
- Custom logo, menus, and widget areas
- Accessible (skip links, ARIA attributes, screen reader text)
- Block editor support (wide/full alignment, responsive embeds)

## Structure

```
PinLightning/
├── assets/
│   ├── css/main.css       # Theme styles
│   ├── js/main.js         # Theme scripts
│   ├── images/            # Theme images
│   └── fonts/             # Custom fonts
├── inc/
│   ├── customizer.php     # Customizer settings
│   └── template-tags.php  # Template helper functions
├── template-parts/
│   ├── content.php        # Post loop content
│   ├── content-page.php   # Page content
│   ├── content-single.php # Single post content
│   ├── content-search.php # Search results content
│   └── content-none.php   # No results content
├── 404.php                # 404 error page
├── archive.php            # Archive template
├── comments.php           # Comments template
├── footer.php             # Site footer
├── functions.php          # Theme setup & configuration
├── header.php             # Site header
├── index.php              # Main template
├── page.php               # Page template
├── search.php             # Search results template
├── sidebar.php            # Sidebar template
├── single.php             # Single post template
└── style.css              # Theme metadata
```

## Installation

1. Download or clone this repository into `wp-content/themes/`
2. Activate the theme from **Appearance > Themes** in the WordPress admin
3. Configure menus under **Appearance > Menus**
4. Customize colors and logo under **Appearance > Customize**

## Requirements

- WordPress 5.9+
- PHP 7.4+

## License

GNU General Public License v2 or later
