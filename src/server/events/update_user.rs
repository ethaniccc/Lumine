use crate::can_io;
use lumine_protocol::CanIo;

can_io! {
    struct UpdateUser {
        pub action: Action,
        pub identifier: String,
    }
}

#[derive(Debug, Clone)]
pub enum Action {
    Unknown = -1,
    Add = 0x00,
    Remove = 0x01,
}

impl From<i32> for Action {
    fn from(value: i32) -> Self {
        match value {
            0x00 => Self::Add,
            0x01 => Self::Remove,
            _ => Self::Unknown,
        }
    }
}

impl CanIo for Action {
    fn write(&self, vec: &mut Vec<u8>) {
        (self.clone() as i32).write(vec)
    }

    fn read(src: &[u8], offset: &mut usize) -> lumine_protocol::Result<Self> {
        Ok(Self::from(i32::read(src, offset)?))
    }
}
