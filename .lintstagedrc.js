module.exports = {
  '*.php': (filenames) => {
    const files = filenames.map((file) => JSON.stringify(file)).join(' ');
    return [
      `vendor/bin/rector process ${files}`,
      `vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php ${files}`,
      `vendor/bin/phpstan analyse ${files}`,
      'php bin/console lint:container',
    ];
  },
  '*.{yaml,yml}': (filenames) => {
    const files = filenames.map((file) => JSON.stringify(file)).join(' ');
    return [
      `php bin/console lint:yaml --parse-tags ${files}`,
      `npx prettier --write ${files}`,
      'php bin/console lint:container',
    ];
  },
  'templates/**/*.twig': (filenames) => {
    const files = filenames.map((file) => JSON.stringify(file)).join(' ');
    return [`php bin/console lint:twig ${files}`];
  },
  '*.{js,mjs,cjs,scss,css,json,md}': (filenames) => {
    const files = filenames.map((file) => JSON.stringify(file)).join(' ');
    return [`npx prettier --write ${files}`];
  },
};
