use crate::can_io;
use lumine_protocol::CanIo;

can_io! {
    struct AlertNotification {
        pub alert_type: Type,
        pub message: String,
    }
}

#[derive(Debug, Clone)]
pub enum Type {
    Unknown = -1,
    Violation = 0x00,
    Punishment = 0x01,
}

impl From<i32> for Type {
    fn from(value: i32) -> Self {
        match value {
            0x00 => Self::Violation,
            0x01 => Self::Punishment,
            _ => Self::Unknown,
        }
    }
}

impl CanIo for Type {
    fn write(&self, vec: &mut Vec<u8>) {
        (self.clone() as i32).write(vec)
    }

    fn read(src: &[u8], offset: &mut usize) -> lumine_protocol::Result<Self> {
        Ok(Self::from(i32::read(src, offset)?))
    }
}
