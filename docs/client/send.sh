#!/bin/sh

# This is an example shell script for sending email using SMTP mailer

# JSON payload
CONTENT='{"sendMail":{"to":["exmaple@gmail.com"],"ccList":[],"bccList":[],"attachments":[],"embedded":[],"subject":"Test Email","body":"<html>Test Email</html>","fromName":"Test System"}}'

# timeout after 1 sec (plaintext TCP)
echo ${CONTENT} | timeout 1 nc -w 1 localhost 3000

# timeout after 1 sec (if you enable SSL on TCP)
echo ${CONTENT} | timeout 1 openssl s_client -connect localhost:3000 -ign_eof

exit 0