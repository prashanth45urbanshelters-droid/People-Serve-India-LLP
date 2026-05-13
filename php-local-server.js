const http = require('http');
const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');

const root = __dirname;
const phpCgi = 'C:\\tmp\\php-8.4.21\\php-cgi.exe';
const host = '127.0.0.1';
const port = Number(process.env.PORT || 8080);

const types = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.webp': 'image/webp',
  '.pdf': 'application/pdf',
};

const server = http.createServer((request, response) => {
  const url = new URL(request.url, `http://${request.headers.host}`);
  const relativePath = decodeURIComponent(url.pathname === '/' ? '/index.html' : url.pathname);
  const filePath = path.normalize(path.join(root, relativePath));

  if (!filePath.startsWith(root)) {
    response.writeHead(403);
    response.end('Forbidden');
    return;
  }

  if (path.extname(filePath).toLowerCase() === '.php') {
    runPhp(request, response, filePath, url);
    return;
  }

  fs.readFile(filePath, (error, contents) => {
    if (error) {
      response.writeHead(404);
      response.end('Not found');
      return;
    }

    response.writeHead(200, {
      'Content-Type': types[path.extname(filePath).toLowerCase()] || 'application/octet-stream',
    });
    response.end(contents);
  });
});

server.listen(port, host, () => {
  fs.writeFileSync(path.join(root, 'php-local-server.log'), `PHP-enabled local server running at http://${host}:${port}/\n`);
});

function runPhp(request, response, filePath, url) {
  if (!fs.existsSync(filePath)) {
    response.writeHead(404);
    response.end('Not found');
    return;
  }

  const chunks = [];
  request.on('data', chunk => chunks.push(chunk));
  request.on('end', () => {
    const body = Buffer.concat(chunks);
    const php = spawn(phpCgi, [], {
      cwd: root,
      env: {
        ...process.env,
        GATEWAY_INTERFACE: 'CGI/1.1',
        REDIRECT_STATUS: '200',
        REQUEST_METHOD: request.method,
        SCRIPT_FILENAME: filePath,
        SCRIPT_NAME: url.pathname,
        QUERY_STRING: url.searchParams.toString(),
        CONTENT_TYPE: request.headers['content-type'] || '',
        CONTENT_LENGTH: String(body.length),
        SERVER_PROTOCOL: 'HTTP/1.1',
        SERVER_SOFTWARE: 'node-php-cgi',
        SERVER_NAME: host,
        SERVER_PORT: String(port),
        DOCUMENT_ROOT: root,
      },
    });

    const output = [];
    const errors = [];
    php.stdout.on('data', chunk => output.push(chunk));
    php.stderr.on('data', chunk => errors.push(chunk));
    php.on('close', code => {
      if (code !== 0) {
        response.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' });
        response.end(Buffer.concat(errors).toString('utf8') || 'PHP execution failed.');
        return;
      }

      const raw = Buffer.concat(output);
      const separator = raw.indexOf(Buffer.from('\r\n\r\n'));
      if (separator === -1) {
        response.writeHead(200);
        response.end(raw);
        return;
      }

      const headerText = raw.subarray(0, separator).toString('utf8');
      const bodyOutput = raw.subarray(separator + 4);
      const headers = {};
      let status = 200;

      headerText.split(/\r\n/).forEach(line => {
        const index = line.indexOf(':');
        if (index === -1) return;
        const name = line.slice(0, index).trim();
        const value = line.slice(index + 1).trim();
        if (name.toLowerCase() === 'status') {
          status = parseInt(value, 10) || status;
        } else {
          headers[name] = value;
        }
      });

      response.writeHead(status, headers);
      response.end(bodyOutput);
    });

    php.stdin.end(body);
  });
}
