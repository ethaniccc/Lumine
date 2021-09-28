use crate::can_io;

can_io! {
    struct CommandResponse {
        pub target: String,
        pub response: String,
    }
}
