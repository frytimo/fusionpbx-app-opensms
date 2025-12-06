
# OpenSMS

OpenSMS is an open-source SMS application designed to be used with FusionPBX(r) system. It provides a developer friendly way to add new providers within this framework.

## Features

- Integration with popular FusionPBX system
- Easy installation
- Support for multiple SMS providers
- Support for media callback URLs

## Installation

### Prerequisites

Before installing OpenSMS, ensure you have the following:

- Git (for cloning the repository)
- An SMS provider supported by the OpenSMS system (Currently only Bandwidth.com)

### Installation Steps

1. Clone the repository:
```bash
git clone https://github.com/frytimo/fusionpbx-app-opensms.git /var/www/fusionpbx/app/opensms
```

1. Run upgrade commands
```bash
php /var/www/fusionpbx/core/upgrade/upgrade.php --defaults
```

1. Configure any additional CIDRs:
```bash
cp config.example.json config.json
# Edit config.json with your settings
```

## Usage

Ensure your callback url is set to https://{your_server}/app/opensms by your provider

### Configuration

The application configures a new ACL call 'Bandwidth SMS'. You can find this in the "Advanced->Access Controls" menu option.

## Contributing

We welcome contributions to OpenSMS! Please follow these steps:

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/your-feature-name`
3. Commit your changes: `git commit -m "Add your feature"`
4. Push to your created branch: `git push origin feature/your-feature-name`
5. Create a Pull Request on github.com

### Code Style

- Write clear, descriptive commit messages
- Document your code

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Support

For support, please open an issue on our [GitHub repository](https://github.com/frytimo/fusionpbx-app-opensms/issues).

## Acknowledgments

- Thanks to all contributors who have helped make OpenSMS better
- Inspired by the FusionPBX messaging application

## Screenshots

![Main Interface](docs/resources/screenshots/main.png)

## Roadmap

- [ ] Add more providers
- [ ] Add support for existing array format in the official FusionPBX SMS application

## Contact

