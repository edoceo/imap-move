# IMAP Move

This will move messages from one IMAP system to another

    php ./imap-move.php \
        --source imap-ssl://userA:secret-password@imap.example.com:993/ \
        --target imap-ssl://userB:secret-passwrod@imap.example.com:993/sub-folder \
        [ --wipe --fake --copy ]

    --fake to just list what would be copied
    --wipe to remove messages after they are copied (move)
    --copy to store copies of the messages in a path


## Shell Wrapper

Included is a shell wrapper to make life a bit easier, see `imap-move.sh`
