use crate::can_io;

can_io! {
    struct Login {
        pub client_protocol: i32,
        pub connection_request: Vec<u8>,
    }
}
