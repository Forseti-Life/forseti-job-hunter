# Custom Themes

This directory contains custom themes for the St. Louis Integration website.

## Theme Structure

Each custom theme should follow Drupal 11 standards:

```
theme_name/
├── theme_name.info.yml
├── theme_name.theme
├── theme_name.libraries.yml
├── css/
├── js/
├── images/
├── templates/
└── config/
```

## Creating a New Theme

1. Create a new directory with your theme name
2. Create the `.info.yml` file with theme metadata
3. Add your theme logic and assets
4. Follow Drupal theming best practices

## Development

For theme development with modern tools:
- Use SCSS/Sass for stylesheets
- Use modern JavaScript (ES6+)
- Implement proper build processes
- Ensure mobile-first responsive design

## Testing

Test themes across:
- Multiple browsers
- Different screen sizes
- Accessibility standards
- Performance metrics