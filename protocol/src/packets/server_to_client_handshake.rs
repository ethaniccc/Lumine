use crate::can_io;

can_io! {
    struct ServerToClientHandshake {
        pub jwt: Vec<u8>,
    }
}
