# Custom Modules

This directory contains custom modules for the Forseti.life website.

## Module Structure

Each custom module should follow Drupal 11 standards:

```
module_name/
├── module_name.info.yml
├── module_name.module
├── src/
│   ├── Controller/
│   ├── Form/
│   ├── Plugin/
│   └── Service/
├── config/
│   └── install/
├── templates/
└── tests/
```

## Creating a New Module

1. Create a new directory with your module name
2. Create the `.info.yml` file with module metadata
3. Add your module logic in the appropriate directories
4. Follow Drupal coding standards

## Coding Standards

All custom modules should follow:
- Drupal coding standards
- PSR-4 autoloading
- Proper documentation
- Unit and functional tests where appropriate

## Testing

Run coding standards check:
```bash
../../../vendor/bin/phpcs --standard=Drupal /path/to/your/module
```

Fix coding standards automatically:
```bash
../../../vendor/bin/phpcbf --standard=Drupal /path/to/your/module
```