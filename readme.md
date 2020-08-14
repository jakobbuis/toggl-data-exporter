# Toggl data exporter
> Formats toggl input data into the format I need reporting to Timewax

## Usage
1. Clone the repository.
1. Copy `.env` to `.env.example`.
1. Run `composer install`.
1. Run `php export.php`.

## Limitations
* Does not support more than 50.000 entries in a week
* Does not support summed entries that are more than 24 hours per day

## License
MIT. See LICENSE file.

## Development
[Jakob Buis](https://www.jakobbuis.nl)
