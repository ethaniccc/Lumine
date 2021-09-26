use crate::{can_io, CanIo};
use std::convert::TryFrom;
use std::io::{Error, ErrorKind};

#[derive(Debug, Clone)]
pub enum Status {
    PlayStatusLoginSuccess = 0x00,
    PlayStatusLoginFailedClient = 0x01,
    PlayStatusLoginFailedServer = 0x02,
    PlayStatusPlayerSpawn = 0x03,
    PlayStatusLoginFailedInvalidTenant = 0x04,
    PlayStatusLoginFailedVanillaEdu = 0x05,
    PlayStatusLoginFailedEduVanilla = 0x06,
    PlayStatusLoginFailedServerFull = 0x07,
}

impl TryFrom<i32> for Status {
    type Error = Error;

    fn try_from(value: i32) -> Result<Self, Self::Error> {
        Ok(match value {
            0x00 => Self::PlayStatusLoginSuccess,
            0x01 => Self::PlayStatusLoginFailedClient,
            0x02 => Self::PlayStatusLoginFailedServer,
            0x03 => Self::PlayStatusPlayerSpawn,
            0x04 => Self::PlayStatusLoginFailedInvalidTenant,
            0x05 => Self::PlayStatusLoginFailedVanillaEdu,
            0x06 => Self::PlayStatusLoginFailedEduVanilla,
            0x07 => Self::PlayStatusLoginFailedServerFull,
            _ => {
                return Err(Error::new(
                    ErrorKind::InvalidData,
                    "Invalid PlayStatus Recieved!",
                ))
            }
        })
    }
}

impl CanIo for Status {
    fn write(&self, vec: &mut Vec<u8>) {
        (self.clone() as i32).write(vec);
    }

    fn read(src: &[u8], offset: &mut usize) -> crate::Result<Self> {
        Ok(Self::try_from(i32::read(src, offset)?)?)
    }
}

can_io! {
    struct PlayStatus {
        pub status: Status,
    }
}
