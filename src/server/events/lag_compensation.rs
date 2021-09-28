use crate::can_io;
use lumine_protocol::Little;

can_io! {
    struct LagCompensation {
        pub identifier: String,
        pub timestamp: Little<i32>,
        pub packet_buffer: Vec<u8>,
        pub is_batch: bool,
    }
}
