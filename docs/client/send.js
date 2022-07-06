const net = require('net');

/**
 * If your SMTP mailer is using default plaintext TCP protocol
 */

// fire and forget email sending (plaintext TCP)
function send(payload) { 
	const client = new net.Socket();
	client.connect(3000, '127.0.0.1', () => {
		client.write(JSON.stringify(payload));
		client.destroy();
	});
}


// email sending with send confirmation (plaintext TCP)
function sendWithResponse(payload) { 
	const client = new net.Socket();
	client.connect(3000, '127.0.0.1', () => {
		client.write(JSON.stringify(payload));
	});
	client.on('data', (data) => {
		const response = JSON.parse(data);
		if (response.status && response.status === 'success') {
			console.log('email sent!');
		} else {
			console.log('email failed to send!');
		}
		client.destroy();
	});
	client.on('error', (err) => {
		throw err;
	});
}

/**
 * If your SMTP mailer is using SSL protocol
 */

const fs = require('fs');
const tls = require('tls');

var options = {
    key: fs.readFileSync('selfsigned.key'),
    cert: fs.readFileSync('selfsigned.crt'),
    rejectUnauthorized: false // allow self-signed certs
};

// fire and forget email sending (SSL)
function sendOverSSL(payload) { 
	const client = tls.connect(3000, '127.0.0.1', options, () => {
		client.write(JSON.stringify(payload));
		client.destroy();
	});
}

// email sending with send confirmation (SSL)
function sendWithResponseOverSSL(payload) { 
	const client = tls.connect(3000, '127.0.0.1', options, () => {
		client.write(JSON.stringify(payload));
	});
	client.on('data', (data) => {
		const response = JSON.parse(data);
		if (response.status && response.status === 'success') {
			console.log('email sent!');
		} else {
			console.log('email failed to send!');
		}
		client.destroy();
	});
	client.on('error', (err) => {
		throw err;
	});
}
