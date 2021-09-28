use crate::can_io;
use lumine_protocol::CanIo;

can_io! {
    struct ServerSendData {
        pub event_type: EventType,
        pub identifier: String,
        pub packet_buffer: Vec<u8>,
        pub timestamp: f32,
    }
}

#[derive(Debug, Clone)]
pub enum EventType {
    Unknown = -1,
    PlayerSendPacket = 0x00,
    ServerSendPacket = 0x01,
}

impl From<i32> for EventType {
    fn from(value: i32) -> Self {
        match value {
            0x00 => Self::PlayerSendPacket,
            0x01 => Self::ServerSendPacket,
            _ => Self::Unknown,
        }
    }
}

impl CanIo for EventType {
    fn write(&self, vec: &mut Vec<u8>) {
        (self.clone() as i32).write(vec)
    }

    fn read(src: &[u8], offset: &mut usize) -> lumine_protocol::Result<Self> {
        Ok(Self::from(i32::read(src, offset)?))
    }
}
