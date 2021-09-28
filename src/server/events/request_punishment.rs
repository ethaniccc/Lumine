use lumine_protocol::varint::{ReadProtocolVarIntExt, WriteProtocolVarIntExt};
use lumine_protocol::CanIo;
use std::io::Cursor;

#[derive(Debug, Clone)]
pub struct RequestPunishment {
    pub identifier: String,
    pub punishment_type: PunishmentType,
    pub message: String,
    pub expiration: Option<u32>,
}

impl CanIo for RequestPunishment {
    fn write(&self, vec: &mut Vec<u8>) {
        self.identifier.write(vec);
        self.punishment_type.write(vec);
        self.message.write(vec);
        if let Some(exp) = self.expiration {
            vec.write_var_u32(exp).unwrap();
        }
    }

    fn read(src: &[u8], offset: &mut usize) -> lumine_protocol::Result<Self> {
        let identifier = String::read(src, offset)?;
        let punishment_type = PunishmentType::read(src, offset)?;
        let message = String::read(src, offset)?;
        let mut expiration = None;
        if let PunishmentType::Ban = punishment_type {
            let mut c = Cursor::new(src);
            c.set_position(*offset as u64);
            expiration = Option::from(c.read_var_u32()?.0);
            *offset = c.position() as usize;
        };
        Ok(Self {
            identifier,
            punishment_type,
            message,
            expiration,
        })
    }
}

#[derive(Debug, Clone)]
pub enum PunishmentType {
    Unknown = -1,
    Kick = 0x00,
    Ban = 0x01,
}

impl From<i32> for PunishmentType {
    fn from(value: i32) -> Self {
        match value {
            0x00 => Self::Kick,
            0x01 => Self::Ban,
            _ => Self::Unknown,
        }
    }
}

impl CanIo for PunishmentType {
    fn write(&self, vec: &mut Vec<u8>) {
        (self.clone() as i32).write(vec)
    }

    fn read(src: &[u8], offset: &mut usize) -> lumine_protocol::Result<Self> {
        Ok(Self::from(i32::read(src, offset)?))
    }
}
