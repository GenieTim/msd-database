# msd-database

Symfony/Doctrine application to use to build a simple material safety data application with API.
The original use was to have a backend for [ChemWizard](https://github.com/BernhardWebstudio/ChemWizard).
Note that the data might be fetched by scraping other websites – make sure to get permissions first.

## Installation

Please note: the following information might not be sufficient in case this is your first 
Symfony project – I recommend you checkout some of their gettings started documentation.
In case you have/had troubles, feel free to open an issue so I can help you 
or a PR to improve this README.

Well, well, well – as this is a full web application, you will need a webserver. 
On this webserver, you need a serving software, such as Apache or Nginx. 
And you need a domain to access the site.
Alternatively, you use the local development servers offered by Symfony.

In any case, at some point you will have to download/clone this repository, 
then install all dependencies using `composer install` 
(make sure you have PHP and [composer](https://getcomposer.org/download/) installed for this),
as well as `yarn install` (make sure you have [yarn](https://classic.yarnpkg.com/en/) installed first).

Additionally, you will require a database and put the credentials in a way that Symfony can find them.
Checkout [./.env.dist](./.env.dist) as an overvew over the parameters, which have to be set. 

Then, you will want to build the project: run `./bin/update.sh` to compile all assets etc.

## Usage

If you've come this far, open your browser, point it to the domain where this app is installed, 
and use the app – it's not hard from here.

## Contributing

Yes, please!

There are quite a few things which could/should be done:

### TODO

- [ ] Improve search speed & stability

Add Sources, e.g.:
- [ ] DSSTOX
- [ ] ECHA
- [ ] Gestis
