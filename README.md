# IMAP Move

This will move messages from one IMAP system to another

    php ./imap-move.php \
        --source imap-ssl://userA:secret-password@imap.example.com:993/ \
        --target imap-ssl://userB:secret-passwrod@imap.example.com:993/sub-folder \
        [ --wipe --fake --copy ]

    --fake to just list what would be copied
    --wipe to remove messages after they are copied (move)
    --copy to store copies of the messages in a path

The source/target is of the form:

    SCHEME://USER:PASS@HOST:PORT/[SUBFOLDER]

SCHEME is one of the following:

    * imap-ssl	# most commonly used
    * imap-tls
    * imap-novalidate-cert		# use on shared hosts that fail due to invalid certificate
    
If you get the error - "Couldn't open stream {server.example.com:143}INBOX", use the imap-novalidate-cert scheme.

## Shell Wrapper

Included is a shell wrapper to make life a bit easier, see `imap-move.sh`
